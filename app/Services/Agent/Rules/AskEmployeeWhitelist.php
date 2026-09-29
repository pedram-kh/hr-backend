<?php

namespace App\Services\Agent\Rules;

use App\Services\Agent\Rule;
use App\Services\Agent\ToolResult;
use App\Services\Agent\TurnState;
use App\Services\Agent\Verdict;
use App\Services\Answer\TurnOutcome;
use App\Services\ChatService;

/**
 * Sprint 13, build step 5 (plan.md §B.4.1) — `pre_call:ask_employee`. Any
 * failure here means the ask never reaches the employee (§B.4's own
 * ordering); the per-conversation counter (check 5 in the plan's list) is
 * NOT re-checked here — it is already the generic `pre_call` boundary's job
 * (`ClarificationBudgetRule`, since `ask_employee->countsAsClarification()`
 * is `true`), so a third ask never even reaches this rule.
 *
 * §F.4 applied (drop `work_regime` — `employees.employment_type` is `NOT
 * NULL`, always in the planner's scope summary already): `ALLOWED_TOPICS`
 * ships with only `sub_question` and `job_category` in v1.
 *
 * §F.15 (the `period` topic — asking "which year?" and threading a past
 * `asOfDate` into `salary_lookup`/`reference_fact`/`convenio_search`) is
 * genuinely NEW behaviour classic does not have at all (unlike
 * `selectedJobCategoryId`, which mirrors an EXISTING classic two-turn
 * mechanism the loop needed access to mid-turn — the standing "gap of that
 * shape" rule does not cover inventing a new capability). Per §F.15's own
 * documented alternative ("drop `period` from the whitelist in v1"), this
 * build takes the safe default absent explicit sign-off — same posture as
 * §F.2's sectioned answers. `period` is NOT in `ALLOWED_TOPICS`; flagged in
 * review.md for a decision before CP-1.
 *
 * §F.7 (an employee's question ASSERTING a different Directory value than
 * what is on file, e.g. "si fuera del grupo 3…") is an explicitly open
 * question in the plan (no firm recommendation, unlike every other §F item).
 * This rule takes the simplest safe reading: ANY `ask_employee` call whose
 * proposed question text hits a `FORBIDDEN_FIELDS` pattern for a Directory
 * field that is already PRESENT is treated as `asserted_differs` outright —
 * no attempt is made to detect hypothetical/conditional phrasing in the
 * ORIGINAL employee question. This never under-escalates (§F.7's "never
 * answer on the asserted value" is upheld either way); it may over-escalate
 * a legitimate "what does my convenio say about…" question that happens to
 * mention a forbidden-field word without asserting a different value for
 * it — recorded as a known conservative simplification, not a silent gap.
 */
final class AskEmployeeWhitelist implements Rule
{
    public const ALLOWED_TOPICS = [
        'sub_question',
        'job_category',
    ];

    /** @var array<string,list<string>> */
    public const FORBIDDEN_FIELDS = [
        'professional_group' => ['/\bgrupo(\s+profesional)?\b/iu', '/\bnivel\b/iu', '/\bsub-?[aá]rea\b/iu'],
        'convenio' => ['/\bconvenio\b/iu'],
        'territory' => ['/\b(provincia|territorio|comunidad\s+aut[oó]noma|centro\s+de\s+trabajo)\b/iu'],
        'seniority' => ['/\b(antig[uü]edad|fecha\s+de\s+(alta|ingreso)|a[nñ]os\s+(en|de)\s+la\s+empresa|trienios?)\b/iu'],
        'contract_type' => ['/\b(tipo\s+de\s+contrato|indefinid|temporal|fijo[\s-]+discontinu|eventual|interin)\w*/iu'],
        'salary_received' => ['/\b(n[oó]mina|cu[aá]nto\s+(cobras|cobraste|te\s+pagan)|salario\s+(percibido|recibido))\b/iu'],
    ];

    public function id(): string
    {
        return 'ask_employee_whitelist';
    }

    public function evaluate(TurnState $state, ?array $call, ?ToolResult $result): Verdict
    {
        $input = $call['input'] ?? [];
        $topic = $input['topic'] ?? null;
        $question = is_string($input['question'] ?? null) ? $input['question'] : '';

        // Check 1: topic whitelist.
        if (! is_string($topic) || ! in_array($topic, self::ALLOWED_TOPICS, true)) {
            return Verdict::deny('topic debe ser uno de: '.implode(', ', self::ALLOWED_TOPICS), $this->id());
        }

        // Check 2: forbidden-field smuggling under any allowed topic.
        foreach (self::FORBIDDEN_FIELDS as $field => $patterns) {
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $question) === 1) {
                    return $this->forbiddenFieldVerdict($field, $state);
                }
            }
        }

        // Check 4: ≤200 characters, exactly one question.
        if (mb_strlen($question) > 200 || substr_count($question, '?') > 1) {
            return Verdict::deny('la pregunta debe tener como máximo 200 caracteres y ser una sola pregunta', $this->id());
        }

        // Check 3 (§B.6.3's general-lane figure/entitlement post-check),
        // closed in step 9: `GeneralLanePostCheck::scan()` is a plain,
        // stateless method built precisely so this rule can call it directly
        // on the PROPOSED question text (there is no `ToolResult` at this
        // call site — the ask hasn't been asked yet, so the `post_call:
        // general_knowledge` rule boundary doesn't apply here at all).
        $hit = GeneralLanePostCheck::scan($question);
        if ($hit !== null) {
            return Verdict::deny("la pregunta propuesta contiene una cifra o un derecho concreto no verificado ({$hit['pattern_id']})", $this->id());
        }

        return Verdict::allow();
    }

    private function forbiddenFieldVerdict(string $field, TurnState $state): Verdict
    {
        if ($field === 'salary_received') {
            // Not a Directory field at all — there is nothing to escalate
            // about; the planner just has the wrong tool. `salary_lookup`
            // answers this deterministically; asking the employee to
            // self-report never should have been proposed.
            return Verdict::deny('no preguntes el salario percibido — usa salary_lookup', $this->id());
        }

        $empty = match ($field) {
            'professional_group' => $state->employee->convenio_group_id === null,
            'seniority' => $state->employee->start_date === null,
            'contract_type' => true, // §F.5 — no column exists at all; always "not captured".
            default => false, // convenio_id / territory_id are NOT NULL — always "present".
        };

        $subOutcome = $empty ? $field : 'asserted_differs';

        $trace = $state->trace;
        $trace['floor_decision'] = [
            'path' => 'agent_ask_employee',
            'outcome' => 'escalate',
            'escalation_reason' => 'profile_incomplete',
            'authority_used' => [],
            'note' => "ask_employee blocked a forbidden-field ask ({$field})",
        ];
        $trace['agent']['profile_incomplete'] = ['field' => $subOutcome];

        $outcome = new TurnOutcome('escalate', ChatService::EMPLOYEE_ESCALATION_MESSAGE, [], $trace, 'profile_incomplete');

        return Verdict::forceEscalate($outcome, $this->id());
    }
}
