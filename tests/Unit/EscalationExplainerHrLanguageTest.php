<?php

namespace Tests\Unit;

use App\Support\EscalationExplainer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Sprint 7g fast-follow (ADR-0029), part 2 — the HR-facing text (`asked`,
 * `found`, `stopped_reason`, `fix_action`, and the `factsToSentences()`
 * rendering built from them) must read like something a Recursions Humanos
 * reviewer wrote, not like an engineer's incident note. This is the
 * regression guard for the class of leak found live in a self-audit: ADR
 * numbers, internal check/gate names (`Check A`/`Check B`), sprint/
 * correction codenames, and engineering nouns ("router", "modelo barato")
 * had crept into copy that a real HR reviewer reads on every card.
 *
 * `fix_surface`/`fix_link` are DELIBERATELY excluded — they name real admin
 * UI tabs/routes (e.g. "AI tagging (revisión)", "Guardarraíles") which are
 * legitimate navigation targets, not engineering internals leaking through.
 *
 * The technical detail this strips out is NOT lost — it lives in `trace`,
 * which every escalation card already carries for whoever needs to dig
 * deeper (see `EscalationController::show()`).
 */
class EscalationExplainerHrLanguageTest extends TestCase
{
    /** @return list<string> a short, human-readable label for each forbidden pattern, paired with its regex */
    public static function forbiddenPatterns(): array
    {
        return [
            'ADR number (e.g. ADR-0015)' => '/\bADR-?\s*\d{2,}\b/i',
            'internal check/gate name (e.g. "Check A")' => '/\bcheck\s*[ab]\b/i',
            'the word "router"' => '/\brouter\b/i',
            'the phrase "modelo barato"' => '/\bmodelo\s*barato\b/i',
            'sprint codename (e.g. "Sprint 6")' => '/\bsprint\s*\d+/i',
            'correction codename (e.g. "Corrección-03")' => '/\bcorrecci[oó]n-\d+/i',
            'internal "Fix N" codename' => '/\bfix\s*\d+\b/i',
        ];
    }

    /** Every (asked, found, stopped_reason, fix_action) tuple across the full MATRIX, built the same way a real card is. */
    public static function allFactTexts(): array
    {
        $cases = [];
        foreach (EscalationExplainer::MATRIX as $key) {
            [$reason, $subOutcome] = explode('.', $key, 2);
            // Publish reasons have no live detector branch (no card is ever
            // created for them today) — reach them the same way the guard
            // test and EscalationExplainerTest do: introspect the registry
            // directly rather than through explain()'s detection path.
            $facts = self::factsFor($reason, $subOutcome);
            $cases[$key] = [$key, $facts];
        }

        return $cases;
    }

    private static function factsFor(string $reason, string $subOutcome): array
    {
        // A minimal trace with just enough shape for every detector branch to
        // resolve to $subOutcome without throwing — mirrors the fixtures in
        // EscalationExplainerTest::cases(), trimmed to what each branch reads.
        $traceByKey = [
            'sensitive_topic.pattern_baseline' => ['guardrail_check' => ['rule' => 'sensitive_topic']],
            'sensitive_topic.admin_blocked_topic' => ['guardrail_check' => ['layer' => 'admin', 'matched_pattern' => 'xyz']],
            'off_domain.legal_medical' => ['guardrail_check' => ['rule' => 'legal_medical']],
            'off_domain.other_employee_data' => ['guardrail_check' => ['rule' => 'other_employee_data']],
            'off_domain.router_off_domain' => ['guardrail_check' => [], 'router_decision' => ['note' => 'router classified off_domain']],
            'off_domain.admin_off_domain' => ['guardrail_check' => ['layer' => 'admin', 'matched_pattern' => 'xyz'], 'router_decision' => []],
            'explicit_request.explicit_request' => [],
            'low_confidence.no_retrieval' => ['floor_decision' => ['check_a_retrieval' => false, 'note' => 'no eligible chunks'], 'retrieval' => ['eligible_total' => 0]],
            'low_confidence.weak_retrieval' => ['floor_decision' => ['check_a_retrieval' => false, 'note' => 'eligible chunks but all below retrieval floor'], 'retrieval' => ['top_score' => 0.2]],
            'low_confidence.citations_failed' => ['floor_decision' => ['check_a_retrieval' => true, 'check_b_citations' => false]],
            'low_confidence.figure_not_grounded' => ['floor_decision' => ['check_a_retrieval' => true, 'check_b_citations' => true, 'figure_grounding' => ['grounded' => false, 'ungrounded' => ['37 días']]]],
            'low_confidence.entailment_failed' => ['floor_decision' => ['check_a_retrieval' => true, 'check_b_citations' => true, 'figure_grounding' => ['grounded' => true], 'grounding' => ['grounded' => false, 'ungrounded' => ['5 días extra']]]],
            'low_confidence.grounding_truncated' => ['floor_decision' => ['check_a_retrieval' => true, 'check_b_citations' => true, 'figure_grounding' => ['grounded' => true], 'grounding' => ['grounded' => false, 'trace_fragment' => ['grounding_truncated' => true]], 'note' => 'truncated']],
            'low_confidence.aggregation' => ['aggregation_guard' => ['fired' => true]],
            'low_confidence.cross_path' => ['floor_decision' => ['path' => 'salary_prose_crosspath']],
            'low_confidence.answer_model_not_configured' => ['floor_decision' => ['check_a_retrieval' => true, 'note' => 'answer model not configured']],
            'low_confidence.provider_error' => ['floor_decision' => ['check_a_retrieval' => true, 'note' => 'provider error'], 'synthesis' => ['error' => 'timeout']],
            'low_confidence.unspecified' => ['floor_decision' => ['check_a_retrieval' => true, 'check_b_citations' => true, 'figure_grounding' => ['grounded' => true], 'grounding' => ['grounded' => true]]],
            'conflict.fact_vs_convenio' => ['composition' => ['conflict' => ['unit' => 'dia', 'fact_values' => ['90'], 'prose_values' => ['60']]], 'reference_fact' => ['fact_uuid' => 'x']],
            'salary_coverage_gap.no_convenio' => ['salary' => ['note' => 'no convenio on profile']],
            'salary_coverage_gap.no_table' => ['salary' => ['note' => 'no salary table']],
            'salary_coverage_gap.future_only' => ['salary' => ['note' => 'not-yet-effective']],
            'salary_coverage_gap.category_unresolved' => ['salary' => ['note' => 'selected category not valid']],
            'salary_coverage_gap.no_row_for_category' => ['salary' => ['note' => 'no salary row for this category']],
            'reference_fact_coverage_gap.no_convenio' => ['reference_fact' => ['note' => 'no convenio on profile']],
            'reference_fact_coverage_gap.no_reference_data' => ['reference_fact' => ['note' => 'no verified in-scope in-validity fact', 'coverage_gap_detail' => ['case' => 'none_recorded']]],
            'reference_fact_coverage_gap.only_needs_review' => ['reference_fact' => ['note' => 'no verified in-scope in-validity fact', 'coverage_gap_detail' => ['case' => 'only_needs_review']]],
            'reference_fact_coverage_gap.out_of_validity' => ['reference_fact' => ['note' => 'no verified in-scope in-validity fact', 'coverage_gap_detail' => ['case' => 'out_of_validity']]],
            'reference_fact_coverage_gap.employee_group_unknown' => ['reference_fact' => ['note' => 'only per-group/per-category facts exist', 'employee_group_state' => 'unresolved']],
            'reference_fact_coverage_gap.group_structure_not_approved' => ['reference_fact' => ['note' => 'only per-group/per-category facts exist', 'employee_group_state' => 'unapproved_or_missing']],
            'reference_fact_coverage_gap.subarea_not_recorded' => ['reference_fact' => ['note' => 'group scope is indeterminate: the employee\'s sub-area is unknown']],
            'reference_fact_coverage_gap.group_split_since_fact_bound' => ['reference_fact' => ['note' => 'group scope is indeterminate: the convenio now splits into sub-areas that carry different values']],
            'reference_fact_coverage_gap.same_validity_conflict' => ['reference_fact' => ['note' => 'two verified facts with the same most-recent validity and differing values', 'validity_selection' => 'ambiguous_conflict']],
        ];

        $key = "{$reason}.{$subOutcome}";
        if (isset($traceByKey[$key])) {
            return EscalationExplainer::explain($reason, $traceByKey[$key]);
        }

        // publish.* — no live detector; read the registry entry directly, the
        // same way EscalationExplainerGuardTest / EscalationExplainerTest do.
        $ref = new \ReflectionClass(EscalationExplainer::class);
        $registry = $ref->getMethod('registry');
        $built = $registry->invoke(null)[$reason][$subOutcome]([]);
        $built['reason'] = $reason;
        $built['sub_outcome'] = $subOutcome;

        return $built;
    }

    #[DataProvider('allFactTexts')]
    public function test_hr_facing_text_has_no_engineer_vocabulary(string $matrixKey, array $facts): void
    {
        $hrText = implode(' ', [
            $facts['asked'],
            $facts['found'],
            $facts['stopped_reason'],
            $facts['fix_action'],
        ]);

        foreach (self::forbiddenPatterns() as $label => $pattern) {
            $this->assertDoesNotMatchRegularExpression(
                $pattern,
                $hrText,
                "MATRIX entry '{$matrixKey}' HR-facing text contains a {$label}: \"{$hrText}\""
            );
        }
    }

    #[DataProvider('allFactTexts')]
    public function test_hr_facing_text_does_not_leak_the_raw_reason_or_sub_outcome_enum_token(string $matrixKey, array $facts): void
    {
        [$reason, $subOutcome] = explode('.', $matrixKey, 2);
        $hrText = implode(' ', [
            $facts['asked'],
            $facts['found'],
            $facts['stopped_reason'],
            $facts['fix_action'],
        ]);

        // The enum tokens themselves (e.g. "sensitive_topic", "router_off_domain")
        // must never appear verbatim in the HR-facing prose — that is the
        // system's internal name for the case, not a description of it.
        $this->assertStringNotContainsString($reason, $hrText, "MATRIX entry '{$matrixKey}' leaks its own reason token '{$reason}' into HR-facing text");
        $this->assertStringNotContainsString($subOutcome, $hrText, "MATRIX entry '{$matrixKey}' leaks its own sub_outcome token '{$subOutcome}' into HR-facing text");
    }

    public function test_facts_to_sentences_rendering_has_no_engineer_vocabulary_either(): void
    {
        foreach (self::allFactTexts() as [$matrixKey, $facts]) {
            $sentence = EscalationExplainer::factsToSentences($facts);

            foreach (self::forbiddenPatterns() as $label => $pattern) {
                $this->assertDoesNotMatchRegularExpression(
                    $pattern,
                    $sentence,
                    "factsToSentences() for MATRIX entry '{$matrixKey}' contains a {$label}: \"{$sentence}\""
                );
            }
        }
    }
}
