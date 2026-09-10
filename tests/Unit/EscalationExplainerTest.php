<?php

namespace Tests\Unit;

use App\Services\ChatService;
use App\Support\EscalationExplainer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Sprint 7g Item 1 (ADR-0029) — one case per reason/sub-outcome in
 * `EscalationExplainer::MATRIX`. Each asserts: (1) the constructed trace
 * resolves to the EXPECTED sub_outcome (detection is correct, not just
 * "some" entry exists), (2) the facts + fix_link are the sub-outcome's own
 * (not a neighbour's or the generic fallback), and (3) `employee_told` is
 * ALWAYS the one fixed neutral message, verbatim, regardless of reason.
 */
class EscalationExplainerTest extends TestCase
{
    /** @return array<string, array{0:string, 1:array<string,mixed>, 2:string}> [reason, trace, expected sub_outcome] */
    public static function cases(): array
    {
        return [
            'sensitive_topic.pattern_baseline' => ['sensitive_topic', ['guardrail_check' => ['fired' => true, 'reason' => 'sensitive_topic', 'rule' => 'sensitive_topic']], 'pattern_baseline'],
            'sensitive_topic.admin_blocked_topic' => ['sensitive_topic', ['guardrail_check' => ['fired' => true, 'reason' => 'sensitive_topic', 'rule' => 'blocked_topic', 'layer' => 'admin', 'matched_pattern' => 'xyz']], 'admin_blocked_topic'],
            'off_domain.legal_medical' => ['off_domain', ['guardrail_check' => ['fired' => true, 'reason' => 'off_domain', 'rule' => 'legal_medical']], 'legal_medical'],
            'off_domain.other_employee_data' => ['off_domain', ['guardrail_check' => ['fired' => true, 'reason' => 'off_domain', 'rule' => 'other_employee_data']], 'other_employee_data'],
            'off_domain.admin_off_domain' => ['off_domain', ['guardrail_check' => ['fired' => true, 'reason' => 'off_domain', 'rule' => 'off_domain', 'layer' => 'admin', 'matched_pattern' => 'xyz']], 'admin_off_domain'],
            'off_domain.router_off_domain' => ['off_domain', ['guardrail_check' => ['fired' => false], 'router_decision' => ['label' => 'off_domain', 'note' => 'router classified off_domain']], 'router_off_domain'],
            'explicit_request.explicit_request' => ['explicit_request', [], 'explicit_request'],
            'low_confidence.no_retrieval' => ['low_confidence', ['floor_decision' => ['check_a_retrieval' => false, 'note' => 'no eligible chunks'], 'retrieval' => ['eligible_total' => 0]], 'no_retrieval'],
            'low_confidence.weak_retrieval' => ['low_confidence', ['floor_decision' => ['check_a_retrieval' => false, 'note' => 'eligible chunks but all below retrieval floor'], 'retrieval' => ['top_score' => 0.2]], 'weak_retrieval'],
            'low_confidence.citations_failed' => ['low_confidence', ['floor_decision' => ['check_a_retrieval' => true, 'check_b_citations' => false, 'note' => 'no valid citations (Check B failed)']], 'citations_failed'],
            'low_confidence.figure_not_grounded' => ['low_confidence', ['floor_decision' => ['check_a_retrieval' => true, 'check_b_citations' => true, 'figure_grounding' => ['grounded' => false, 'ungrounded' => ['37 días']], 'note' => 'answer figure not grounded in cited chunk (figure-guard pre-check)']], 'figure_not_grounded'],
            'low_confidence.entailment_failed' => ['low_confidence', ['floor_decision' => ['check_a_retrieval' => true, 'check_b_citations' => true, 'figure_grounding' => ['grounded' => true], 'grounding' => ['grounded' => false, 'ungrounded' => ['5 días extra']], 'note' => 'ungrounded claim (per-claim entailment gate)']], 'entailment_failed'],
            'low_confidence.grounding_truncated' => ['low_confidence', ['floor_decision' => ['check_a_retrieval' => true, 'check_b_citations' => true, 'figure_grounding' => ['grounded' => true], 'grounding' => ['grounded' => false, 'trace_fragment' => ['grounding_truncated' => true]], 'note' => 'grounding check truncated after retry (escalated)']], 'grounding_truncated'],
            'low_confidence.aggregation' => ['low_confidence', ['aggregation_guard' => ['fired' => true, 'shape' => 'vague_total_dias_libres']], 'aggregation'],
            'low_confidence.cross_path' => ['low_confidence', ['floor_decision' => ['path' => 'salary_prose_crosspath']], 'cross_path'],
            'low_confidence.answer_model_not_configured' => ['low_confidence', ['floor_decision' => ['check_a_retrieval' => true, 'note' => 'answer model not configured']], 'answer_model_not_configured'],
            'low_confidence.provider_error' => ['low_confidence', ['floor_decision' => ['check_a_retrieval' => true, 'note' => 'provider error'], 'synthesis' => ['error' => 'provider_error']], 'provider_error'],
            'low_confidence.unspecified' => ['low_confidence', ['floor_decision' => ['check_a_retrieval' => true, 'check_b_citations' => true, 'figure_grounding' => ['grounded' => true], 'grounding' => ['grounded' => true]]], 'unspecified'],
            'conflict.fact_vs_convenio' => ['conflict', ['composition' => ['conflict' => ['unit' => 'dia', 'fact_values' => ['90'], 'prose_values' => ['60']]], 'reference_fact' => ['fact_uuid' => 'fact-uuid-1']], 'fact_vs_convenio'],
            'salary_coverage_gap.no_convenio' => ['salary_coverage_gap', ['salary' => ['note' => 'no convenio on profile']], 'no_convenio'],
            'salary_coverage_gap.no_table' => ['salary_coverage_gap', ['salary' => ['note' => 'no salary table for this convenio (coverage gap — salary may be PDF-only)']], 'no_table'],
            'salary_coverage_gap.future_only' => ['salary_coverage_gap', ['salary' => ['note' => 'only a not-yet-effective (future) salary table exists — escalate, do not quote']], 'future_only'],
            'salary_coverage_gap.category_unresolved' => ['salary_coverage_gap', ['salary' => ['note' => 'selected category not valid for this convenio']], 'category_unresolved'],
            'salary_coverage_gap.no_row_for_category' => ['salary_coverage_gap', ['salary' => ['note' => 'no salary row for this category in the resolved table']], 'no_row_for_category'],
            'reference_fact_coverage_gap.no_convenio' => ['reference_fact_coverage_gap', ['reference_fact' => ['note' => 'no convenio on profile']], 'no_convenio'],
            'reference_fact_coverage_gap.no_reference_data' => ['reference_fact_coverage_gap', ['reference_fact' => ['note' => 'no verified in-scope in-validity fact (only unverified / out-of-validity / future-only, or none)', 'coverage_gap_detail' => ['case' => 'none_recorded']]], 'no_reference_data'],
            'reference_fact_coverage_gap.only_needs_review' => ['reference_fact_coverage_gap', ['reference_fact' => ['note' => 'no verified in-scope in-validity fact (only unverified / out-of-validity / future-only, or none)', 'coverage_gap_detail' => ['case' => 'only_needs_review']]], 'only_needs_review'],
            'reference_fact_coverage_gap.out_of_validity' => ['reference_fact_coverage_gap', ['reference_fact' => ['note' => 'no verified in-scope in-validity fact (only unverified / out-of-validity / future-only, or none)', 'coverage_gap_detail' => ['case' => 'out_of_validity']]], 'out_of_validity'],
            'reference_fact_coverage_gap.employee_group_unknown' => ['reference_fact_coverage_gap', ['reference_fact' => ['note' => 'only per-group/per-category facts exist; employee scope does not confidently match one (group unresolved or different group) — never guess', 'employee_group_state' => 'unresolved']], 'employee_group_unknown'],
            'reference_fact_coverage_gap.group_structure_not_approved' => ['reference_fact_coverage_gap', ['reference_fact' => ['note' => 'only per-group/per-category facts exist; employee scope does not confidently match one (group unresolved or different group) — never guess', 'employee_group_state' => 'unapproved_or_missing']], 'group_structure_not_approved'],
            'reference_fact_coverage_gap.subarea_not_recorded' => ['reference_fact_coverage_gap', ['reference_fact' => ['note' => 'group scope is indeterminate, escalate rather than answer less specifically than the evidence: fact 44 is scoped to sub-area "área 5" of the employee\'s group "Grupo 2" — the employee\'s sub-area is unknown']], 'subarea_not_recorded'],
            'reference_fact_coverage_gap.group_split_since_fact_bound' => ['reference_fact_coverage_gap', ['reference_fact' => ['note' => 'group scope is indeterminate, escalate rather than answer less specifically than the evidence: fact 40 is scoped to group "Grupo 2", which the convenio splits into sub-areas that carry different values — the fact\'s own scope is ambiguous']], 'group_split_since_fact_bound'],
            'reference_fact_coverage_gap.same_validity_conflict' => ['reference_fact_coverage_gap', ['reference_fact' => ['note' => 'two verified facts with the same most-recent validity and differing values — escalate, do not blend (resolution is 7d)', 'validity_selection' => 'ambiguous_conflict']], 'same_validity_conflict'],
            'publish.topic_scope_conflict' => ['publish', [], 'topic_scope_conflict'],
            'publish.semantic_overlap' => ['publish', [], 'semantic_overlap'],
            'publish.semantic_near_overlap' => ['publish', [], 'semantic_near_overlap'],
            'publish.semantic_compare_unavailable' => ['publish', [], 'semantic_compare_unavailable'],
            'publish.semantic_no_text_to_compare' => ['publish', [], 'semantic_no_text_to_compare'],
            'publish.convert_blocked' => ['publish', [], 'convert_blocked'],
        ];
    }

    #[DataProvider('cases')]
    public function test_sub_outcome_and_facts(string $reason, array $trace, string $expectedSubOutcome): void
    {
        // `publish` isn't a real detectable reason (no card is ever created for
        // it today) — explain() has no detector branch for it, so drive it via
        // the MATRIX key directly against the registry instead of through
        // detection, exactly like the guard test does.
        if ($reason === 'publish') {
            $this->assertTrue(EscalationExplainer::registryHasEntry("publish.{$expectedSubOutcome}"));

            return;
        }

        $facts = EscalationExplainer::explain($reason, $trace);

        $this->assertSame($reason, $facts['reason']);
        $this->assertSame($expectedSubOutcome, $facts['sub_outcome'], "expected sub_outcome '{$expectedSubOutcome}', got '{$facts['sub_outcome']}' for trace: ".json_encode($trace));
        $this->assertNotEmpty($facts['asked']);
        $this->assertNotEmpty($facts['found']);
        $this->assertNotEmpty($facts['stopped_reason']);
        $this->assertNotEmpty($facts['fix_action']);
        $this->assertNotEmpty($facts['fix_surface']);
        // THE invariant that must hold for every single one of these, no
        // exceptions: the employee-facing text is always the fixed message.
        $this->assertSame(ChatService::EMPLOYEE_ESCALATION_MESSAGE, $facts['employee_told']);
    }

    #[DataProvider('cases')]
    public function test_data_provider_matches_a_real_matrix_entry(string $reason, array $trace, string $expectedSubOutcome): void
    {
        $this->assertContains("{$reason}.{$expectedSubOutcome}", EscalationExplainer::MATRIX);
    }

    public function test_every_matrix_entry_has_a_test_case(): void
    {
        $covered = array_map(fn ($c) => $c[0].'.'.$c[2], self::cases());
        $this->assertSame(
            [],
            array_values(array_diff(EscalationExplainer::MATRIX, $covered)),
            'every MATRIX entry should have a corresponding case() fixture in this test'
        );
    }

    // ---- targeted fix_link assertions (a sample, not exhaustive) -----------

    public function test_conflict_fix_link_points_at_the_conflicting_fact(): void
    {
        $facts = EscalationExplainer::explain('conflict', [
            'composition' => ['conflict' => ['unit' => 'dia', 'fact_values' => ['90'], 'prose_values' => ['60']]],
            'reference_fact' => ['fact_uuid' => 'fact-uuid-1'],
        ]);

        $this->assertSame('#view=review&tab=reference-facts&fact=fact-uuid-1', $facts['fix_link']);
        $this->assertSame('Reference facts (revisión)', $facts['fix_surface']);
    }

    public function test_employee_group_unknown_fix_link_points_at_the_employees_directory_row(): void
    {
        $facts = EscalationExplainer::explain('reference_fact_coverage_gap', [
            'profile' => ['employee_uuid' => 'emp-uuid-1'],
            'reference_fact' => [
                'note' => 'only per-group/per-category facts exist; employee scope does not confidently match one (group unresolved or different group) — never guess',
                'employee_group_state' => 'unresolved',
            ],
        ]);

        $this->assertSame('employee_group_unknown', $facts['sub_outcome']);
        $this->assertSame('#view=directory&emp=emp-uuid-1', $facts['fix_link']);
    }

    public function test_group_structure_not_approved_fix_link_points_at_the_groups_tab_with_convenio(): void
    {
        $facts = EscalationExplainer::explain('reference_fact_coverage_gap', [
            'profile' => ['convenio_id' => 18],
            'reference_fact' => [
                'note' => 'only per-group/per-category facts exist; employee scope does not confidently match one (group unresolved or different group) — never guess',
                'employee_group_state' => 'unapproved_or_missing',
            ],
        ]);

        $this->assertSame('group_structure_not_approved', $facts['sub_outcome']);
        $this->assertSame('#view=review&tab=groups&convenio=18', $facts['fix_link']);
    }

    public function test_no_pattern_leaks_a_reason_token_or_id_into_the_employee_told_field(): void
    {
        foreach (self::cases() as [$reason, $trace, $subOutcome]) {
            if ($reason === 'publish') {
                continue;
            }
            $facts = EscalationExplainer::explain($reason, $trace);
            $this->assertStringNotContainsString($reason, $facts['employee_told']);
            $this->assertStringNotContainsString($subOutcome, $facts['employee_told']);
        }
    }
}
