<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * The question router (Sprint 2b-2, ADR-0016). Runs AFTER the hardcoded guardrail
 * baseline (GuardrailService) — sensitive / legal-medical / other-employee
 * questions are already escalated and NEVER reach here (nor does an explicit
 * human-request — Sprint 10b's matchesExplicitRequest() pre-check, called
 * directly by ChatService, also runs before this). The router classifies the
 * remaining questions salary | prose | off_domain and routes each to the right
 * path; for a compound question it also yields decomposed subqueries (the recall
 * hardening in ChatService unions one /retrieve per sub-query). Sprint 10b
 * (ADR-0033) adds a second, PARALLEL array, decomposed_queries — situational/
 * colloquial retrieval rephrasings, never a variant of subqueries (plan.md §B.1).
 *
 * Two layers (ADR-0016 "deterministic guards short-circuit the obvious cases"):
 *  1. A high-precision DETERMINISTIC salary PRE-CLASSIFIER (the patterns moved
 *     here from GuardrailService — they no longer ESCALATE; they ROUTE obvious
 *     salary to the SQL path without an LLM call). This supersedes Correction-02.
 *  2. The small/fast-model LLM /route for everything else.
 *
 * FAIL-SAFE is mandatory: router uncertainty (low confidence), a provider/transport
 * error, or no answer-model key → the SAFE prose path (which can still escalate via
 * the answer-or-escalate floor) — NEVER a silent misroute. A confident off_domain
 * escalates; a confident salary routes to SQL; only the uncertain middle defaults
 * to prose.
 *
 * The decision + confidence land in the `router_decision` trace block.
 */
class RouterService
{
    public const SALARY = 'salary';

    public const PROSE = 'prose';

    public const OFF_DOMAIN = 'off_domain';

    /**
     * Deterministic salary PRE-CLASSIFIER (moved from GuardrailService,
     * Correction-02 — but it no longer escalates; a match ROUTES to the SQL salary
     * path). Conservative on the bare verb "paga" (so "¿me paga el gimnasio?" is
     * NOT salary) but broad on the salary nouns. Runs WITHOUT needing a key, so an
     * obvious salary question is answered from SQL even before the answer model
     * is configured.
     *
     * @var list<string>
     */
    private const SALARY_PATTERNS = [
        '/\b(salario|sueldo|n[oó]mina|retribuci[oó]n|remuneraci[oó]n)\b/iu',
        '/\btablas?\s+salarial(es)?\b/iu',
        '/\bpagas?\s+extra/iu',
        '/\bcu[aá]nto\s+(gano|gana|gan[aá]is|cobro|cobra|cobr[aá]is|ingreso|ingresa|me\s+pagan?|se\s+(gana|cobra|paga))\b/iu',
        // Sprint 10b (ADR-0033), colloquial addition: a plus/complemento is a
        // salary-table line item (salary_table_rows.raw_values), same tier as
        // "paga extra" above. No real staging question motivated this — flagged
        // in plan.md §A.1 as the one anticipatory (not corrective) addition.
        '/\bplus(es)?\s+de\s+transporte\b/iu',
        // Sprint 10b, D1-bis (build-authorization amendment, post-review): "SMI"
        // is the acronym for salario mínimo interprofesional — real observed
        // staging traffic ("¿Cuál es el SMI este año?") was misrouted off_domain
        // because it never spells out "salario". This is a genuine lexicon gap,
        // not the pre-existing router-flap the CP-3 staging gold-eval miss was
        // first diagnosed as. The "salario mínimo" spelled-out form is already
        // covered by the bare "salario" pattern above; it is listed explicitly
        // here too, for clarity of intent, not because it is load-bearing.
        '/\bSMI\b/iu',
        '/\bsalario\s+m[ií]nimo(\s+interprofesional)?\b/iu',
    ];

    /**
     * Sprint 10b, Correction-01 (post-D1-bis regression, eyes-on found): a
     * question naming SMI/salario mínimo (interprofesional) is topically salary
     * (it stays IN `SALARY_PATTERNS` above, so it still routes to the SQL path,
     * never the LLM), but it is asking for a STATUTORY figure the State sets —
     * never a cell in the employee's OWN convenio salary table. D1-bis only
     * fixed the case where an employee has no table at all (a real coverage
     * gap happened to produce the right escalation for the wrong reason). It
     * missed `test-navarra@example.com`-shaped employees who DO have a table
     * and a resolvable category: `SalaryAnswerService::answer()` doesn't know
     * WHAT was asked, only WHO is asking, so it answered her own category cell
     * — a real number, but a non-responsive one, to a question about a
     * completely different (national, not per-category) figure.
     *
     * A SUBSET of `SALARY_PATTERNS` (SMI/salario mínimo only — never the bare
     * "salario"/"nómina"/etc. patterns, which DO belong to the employee's own
     * table). Checked by `ChatService` BEFORE it ever calls
     * `SalaryAnswerService::answer()`, so the answer path is never reached for
     * these — regardless of whether the employee has a table, a category, or a
     * row. The contract: SMI questions escalate for every employee profile.
     *
     * @var list<string>
     */
    private const STATUTORY_SALARY_PATTERNS = [
        '/\bSMI\b/iu',
        '/\bsalario\s+m[ií]nimo(\s+interprofesional)?\b/iu',
    ];

    /**
     * Sprint 10b (ADR-0033) — deterministic `explicit_request` pre-check. Mirrors
     * SALARY_PATTERNS's structure and conservatism (narrow first, per spec R5).
     * Runs BEFORE the reference-fact pre-check and the router, but AFTER both
     * guardrail layers (ChatService::handleMessage Step 2c) — a message that is
     * simultaneously sensitive AND a human request must escalate sensitive_topic,
     * never this weaker, contentless reason.
     *
     * The one real motivating case (Sprint 10b plan §A.1 Table 3, card 9):
     * "Quiero hablar con una persona de Recursos Humanos, por favor." — today
     * misrouted to off_domain because nothing upstream of the LLM router catches
     * it and the LLM has no vocabulary reason to call it anything but off-topic.
     *
     * Deliberately narrow: requires a first-person request verb ("quiero" /
     * "necesito" / "me gustaría") + "hablar"/"contactar" + "con". The trailing
     * "una persona"/"alguien" branch carries a NEGATIVE LOOKAHEAD (build
     * authorization D2) so an explicit non-RRHH qualifier does NOT fire —
     * "necesito hablar con alguien de mi equipo sobre el horario" is a normal
     * question, not a request to be routed to a human. Only a BARE request, or
     * one explicitly qualified "de recursos humanos"/"de RR.HH.", fires. An
     * interrogative asking HOW to reach someone ("¿con quién hablo…?") never
     * matches — there is no "quiero/necesito/me gustaría" in it.
     *
     * @var list<string>
     */
    private const EXPLICIT_REQUEST_PATTERNS = [
        '/\b(quiero|necesito|me\s+gustar[ií]a)\s+(hablar|contactar)\s+con\s+(?:una\s+persona|alguien)(?!\s+de\s+(?!(?:recursos\s+humanos|rr\.?\s?hh\.?)\b))\b/iu',
        '/\b(quiero|necesito|me\s+gustar[ií]a)\s+(hablar|contactar)\s+con\s+(recursos\s+humanos|rr\.?\s?hh\.?)\b/iu',
    ];

    public function __construct(
        private readonly ExtractionClient $ai,
        private readonly GuardrailPolicy $policy,
    ) {}

    /**
     * Classify one (already guardrail-cleared) question.
     *
     * @param  string|null  $decryptedKey  the per-call answer-model key, or null if unconfigured
     * @param  array{provider:string,model:string,endpoint:?string}  $routerConfig
     * @return array{label:string, confidence:float, source:string, subqueries:list<string>, model:?string, note:?string, trace_fragment:array<string,mixed>}
     */
    public function classify(string $question, ?string $decryptedKey, array $routerConfig): array
    {
        // --- Layer 1: deterministic salary pre-classifier (no LLM, no key) ------
        if ($this->matchesSalary($question)) {
            // Cross-path guard (Correction-03, Fix 3): a salary keyword matched,
            // but the question ALSO has a clear non-salary clause (a salary+prose
            // compound, e.g. "¿cuánto cobra un peón y cuántas vacaciones tiene?").
            // Do NOT short-circuit the whole turn to SQL-only (which silently
            // dropped the prose half). Flag it cross_path so ChatService can
            // escalate-with-note and the prose half is never silently dropped.
            $proseClauses = $this->crossPathProseClauses($question);
            if ($proseClauses !== []) {
                return $this->decision(
                    label: self::SALARY,
                    confidence: 1.0,
                    source: 'deterministic_salary_crosspath',
                    subqueries: $proseClauses,
                    model: null,
                    note: 'salary+prose cross-path compound — not short-circuited to SQL-only (Correction-03)',
                    crossPath: true,
                );
            }

            return $this->decision(
                label: self::SALARY,
                confidence: 1.0,
                source: 'deterministic_salary',
                subqueries: [],
                model: null,
                note: 'matched deterministic salary pre-classifier',
            );
        }

        // --- No key → cannot call the LLM router. FAIL SAFE to prose (the prose
        // path then escalates honestly "answer model not configured"). ----------
        if ($decryptedKey === null) {
            return $this->decision(
                label: self::PROSE,
                confidence: 0.0,
                source: 'fail_safe',
                subqueries: [],
                model: null,
                note: 'answer model not configured — fail-safe to prose',
            );
        }

        // --- Layer 2: small-LLM /route ------------------------------------------
        $resp = $this->ai->route($question, $decryptedKey, $routerConfig);

        if (isset($resp['error'])) {
            Log::warning('router: /route failed', ['error' => $resp['error']]); // never logs the key

            return $this->decision(
                label: self::PROSE,
                confidence: 0.0,
                source: 'fail_safe',
                subqueries: [],
                model: $routerConfig['model'] ?? null,
                note: 'router error ('.$resp['error'].') — fail-safe to prose',
                traceFragment: ['error' => $resp['error']],
            );
        }

        $label = (string) ($resp['label'] ?? self::PROSE);
        if (! in_array($label, [self::SALARY, self::PROSE, self::OFF_DOMAIN], true)) {
            $label = self::PROSE;
        }
        $confidence = (float) ($resp['confidence'] ?? 0.0);
        $llmSubqueries = array_values(array_filter(array_map(
            fn ($s) => trim((string) $s),
            (array) ($resp['subqueries'] ?? [])
        ), fn ($s) => $s !== ''));
        // Sprint 10b (ADR-0033): situational/colloquial → corpus-vocabulary
        // retrieval rephrasings. A NEW, PARALLEL array — never a variant of
        // subqueries above. subqueries SPLITS a compound question into its
        // constituent topics; decomposed_queries REPHRASES a (possibly
        // single-topic) question's underlying legal concept into convenio/law
        // vocabulary, for retrieval only (plan.md §B.1). Absent key on an older
        // hr-ai response (or hr-ai not yet upgraded) → [] → no behavior change.
        $llmDecomposedQueries = array_values(array_filter(array_map(
            fn ($s) => trim((string) $s),
            (array) ($resp['decomposed_queries'] ?? [])
        ), fn ($s) => $s !== ''));
        // Effective router floor = max(hardcoded floor, admin override) via
        // GuardrailPolicy (Sprint 6, ADR-0019, secondary knob). Raising it pushes
        // more of the uncertain middle to the safe prose path; it can never drop
        // below config('hr.router_confidence_floor').
        $floor = $this->policy->routerFloor();

        // Uncertain → FAIL SAFE to prose (never a confident misroute). A confident
        // off_domain or salary is trusted; the uncertain middle defaults to prose.
        if ($confidence < $floor) {
            return $this->decision(
                label: self::PROSE,
                confidence: $confidence,
                source: 'fail_safe',
                subqueries: $this->resolveSubqueries(self::PROSE, $llmSubqueries, $question),
                model: $routerConfig['model'] ?? null,
                note: 'router confidence below floor — fail-safe to prose',
                traceFragment: $resp['trace_fragment'] ?? [],
                decomposedQueries: $this->resolveDecomposedQueries(self::PROSE, $llmDecomposedQueries),
            );
        }

        return $this->decision(
            label: $label,
            confidence: $confidence,
            source: 'llm',
            subqueries: $this->resolveSubqueries($label, $llmSubqueries, $question),
            model: $routerConfig['model'] ?? null,
            note: null,
            traceFragment: $resp['trace_fragment'] ?? [],
            decomposedQueries: $this->resolveDecomposedQueries($label, $llmDecomposedQueries),
        );
    }

    /**
     * Decide the sub-queries for a prose turn. The LLM decomposition is PRIMARY;
     * when the LLM returns none but the question is deterministically detected as
     * COMPOUND, fall back to a cheap deterministic split (the safety net the spec
     * requires — the Q10 fix must not depend solely on the LLM emitting
     * subqueries). Single-topic questions return [] (no decomposition).
     *
     * @param  list<string>  $llmSubqueries
     * @return list<string>
     */
    private function resolveSubqueries(string $label, array $llmSubqueries, string $question): array
    {
        if ($label !== self::PROSE) {
            return []; // only prose retrieval is decomposed
        }
        if (count($llmSubqueries) >= 2) {
            return $llmSubqueries;
        }

        return $this->deterministicSplit($question);
    }

    /**
     * Decide the decomposed (retrieval-rephrasing) queries for a prose turn
     * (Sprint 10b, ADR-0033). LLM-only — unlike resolveSubqueries() there is no
     * deterministic fallback, because there is no reliable non-LLM way to detect
     * "this question is phrased colloquially/situationally" the way a compound
     * question's punctuation/conjunctions can be detected. Prose-only, same guard
     * as resolveSubqueries(): salary/off_domain questions never retrieve via the
     * union, so a decomposition has nothing to join.
     *
     * @param  list<string>  $llmDecomposedQueries
     * @return list<string>
     */
    private function resolveDecomposedQueries(string $label, array $llmDecomposedQueries): array
    {
        if ($label !== self::PROSE) {
            return [];
        }

        return $llmDecomposedQueries;
    }

    /**
     * True when any deterministic salary pattern matches the text. Public so the
     * Sprint 7c reference-fact pre-check can defer to salary FIRST (Q4: salary
     * keeps its exact path; the reference-fact pre-check runs only on a non-salary
     * question). Behavior-neutral visibility change — the patterns are unchanged.
     */
    public function matchesSalary(string $text): bool
    {
        foreach (self::SALARY_PATTERNS as $pattern) {
            if (preg_match($pattern, $text)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Sprint 10b, Correction-01: true when the question names a STATUTORY
     * salary figure (SMI/salario mínimo) rather than the employee's own
     * convenio table. Public so `ChatService` can check it directly, the same
     * calling convention as `matchesSalary()`, and act on it BEFORE calling
     * `SalaryAnswerService::answer()` — never after, since that service has no
     * way to know what was asked, only who is asking.
     */
    public function matchesStatutorySalaryFigure(string $text): bool
    {
        foreach (self::STATUTORY_SALARY_PATTERNS as $pattern) {
            if (preg_match($pattern, $text)) {
                return true;
            }
        }

        return false;
    }

    /**
     * True when the question is a deterministic `explicit_request` match (Sprint
     * 10b, ADR-0033). Public so ChatService can pre-check it directly — same
     * calling convention as matchesSalary(), same layer (before the reference-
     * fact pre-check and the router; after both guardrail layers).
     */
    public function matchesExplicitRequest(string $text): bool
    {
        foreach (self::EXPLICIT_REQUEST_PATTERNS as $pattern) {
            if (preg_match($pattern, $text)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Cross-path detection (Correction-03, Fix 3): split a salary-matched question
     * into clauses; if at least one clause is salary AND at least one substantive
     * clause is NOT salary, it is a salary+prose compound — return the non-salary
     * (prose) clauses. Otherwise return [] (a pure-salary question, possibly with
     * several salary clauses, routes normally to SQL). Deterministic — the minimum
     * bar that guarantees the prose half is surfaced, not silently dropped.
     *
     * @return list<string>
     */
    public function crossPathProseClauses(string $question): array
    {
        $segments = $this->deterministicSplit($question);
        if (count($segments) < 2) {
            return [];
        }

        $proseClauses = [];
        $hasSalaryClause = false;
        foreach ($segments as $seg) {
            if ($this->matchesSalary($seg)) {
                $hasSalaryClause = true;
            } else {
                $proseClauses[] = $seg;
            }
        }

        return ($hasSalaryClause && $proseClauses !== []) ? $proseClauses : [];
    }

    /**
     * Cheap deterministic compound pre-split (safety net for the LLM
     * decomposition). Splits on multiple "¿…?" segments, or on a coordinating
     * conjunction (" y " / " e " / "; " / " además ") joining two interrogative-ish
     * clauses. Conservative: returns [] unless it finds ≥2 substantive sub-clauses,
     * so a single-topic question is never falsely decomposed.
     *
     * @return list<string>
     */
    public function deterministicSplit(string $question): array
    {
        $q = trim($question);

        // (a) Multiple explicit "¿ … ?" interrogative segments.
        if (preg_match_all('/¿([^?¿]+)\?/u', $q, $m) && count($m[1]) >= 2) {
            $parts = array_map('trim', $m[1]);
            $parts = array_values(array_filter($parts, fn ($p) => mb_strlen($p) >= 8));
            if (count($parts) >= 2) {
                return $parts;
            }
        }

        // (b) A coordinating conjunction joining two clauses. Split on " y "/" e "/
        // "; "/" además " and keep substantive sides. Cheap and conservative — a
        // 5+ char segment counts as a real sub-topic (so the terse "vacaciones y
        // periodo de prueba" splits), but trivial joiners ("él y ella") do not.
        $normalized = preg_replace('/^[¿\s]+|[?\s]+$/u', '', $q) ?? $q;
        $segments = preg_split('/\s+(?:y|e|adem[aá]s)\s+|\s*;\s*/u', $normalized) ?: [];
        $segments = array_map('trim', $segments);
        $segments = array_values(array_filter($segments, fn ($p) => mb_strlen($p) >= 5));
        if (count($segments) >= 2) {
            return $segments;
        }

        return [];
    }

    /**
     * @param  list<string>  $subqueries
     * @param  array<string,mixed>  $traceFragment
     * @param  list<string>  $decomposedQueries
     * @return array{label:string, confidence:float, source:string, subqueries:list<string>, model:?string, note:?string, cross_path:bool, trace_fragment:array<string,mixed>, decomposed_queries:list<string>}
     */
    private function decision(
        string $label,
        float $confidence,
        string $source,
        array $subqueries,
        ?string $model,
        ?string $note,
        bool $crossPath = false,
        array $traceFragment = [],
        array $decomposedQueries = [],
    ): array {
        return [
            'label' => $label,
            'confidence' => $confidence,
            'source' => $source,
            'subqueries' => $subqueries,
            'model' => $model,
            'note' => $note,
            'cross_path' => $crossPath,
            'trace_fragment' => $traceFragment,
            // Sprint 10b (ADR-0033): [] at every call site above that doesn't pass
            // it explicitly — additive by construction (plan.md §B.1).
            'decomposed_queries' => $decomposedQueries,
        ];
    }
}
