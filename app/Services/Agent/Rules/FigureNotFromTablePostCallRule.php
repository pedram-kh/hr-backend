<?php

namespace App\Services\Agent\Rules;

use App\Services\Agent\Rule;
use App\Services\Agent\ToolResult;
use App\Services\Agent\TurnState;
use App\Services\Agent\Verdict;
use App\Services\Answer\TurnOutcome;
use App\Services\ChatService;

/**
 * Sprint 13, CP-2 fix for wt-03 (review.md "wt-03 fix"), the safety net behind
 * {@see SalaryIntentPreCallRule}: ANY synthesised prose answer (`convenio_search` /
 * `national_law`) that contains a euro amount which `salary_lookup` did not produce
 * this turn is discarded and escalated `low_confidence`, sub-outcome
 * `figure_not_from_table`. A salary/pay figure may only come from the salary table
 * (ADR-0006/0027); prose that happens to quote one — for the wrong category, an
 * example row, another year — is exactly the confident-wrong-answer class the agent
 * must not add over classic.
 *
 * Registered BEFORE {@see ProseCheckAPostCallRule} on both prose tools (the engine stops
 * at the first non-allow, and that rule would otherwise force-finish the answer).
 * Agent-only: classic has no equivalent and is byte-identical by contract (§B.1).
 * Digit and spelled-number amounts followed by €/euro(s)/EUR are detected.
 */
final class FigureNotFromTablePostCallRule implements Rule
{
    private const AMOUNT = '/(?:€\s*\d[\d.,]*|\d[\d.,]*\s*(?:€|eur\b|euros?\b)|\b(?:cero|uno|una|dos|tres|cuatro|cinco|seis|siete|ocho|nueve|diez|once|doce|trece|catorce|quince|dieci\w+|veinte|veinti\w+|treinta|cuarenta|cincuenta|sesenta|setenta|ochenta|noventa|cien(?:to|tos)?|doscient\w+|trescient\w+|quinient\w+|mil|millon\w*)(?:\s+(?:y\s+)?\w+){0,3}\s+euros?\b)/iu';

    public function id(): string
    {
        // Deliberately NOT suffixed `_post_call`: this is a CORRECTION (counted in the gate's
        // rule_overrides), not a mandated post-call termination.
        return 'figure_not_from_table_guard';
    }

    public function evaluate(TurnState $state, ?array $call, ?ToolResult $result): Verdict
    {
        if ($result === null || $result->status !== ToolResult::TERMINAL || ! $result->terminalOutcome instanceof TurnOutcome) {
            return Verdict::allow();
        }
        if ($result->terminalOutcome->outcome !== 'answer') {
            return Verdict::allow();
        }

        $answer = $result->terminalOutcome->answer;
        if (preg_match_all(self::AMOUNT, $answer, $m) === 0) {
            return Verdict::allow();
        }

        $tableText = $this->salaryLookupText($state);
        $foreign = array_values(array_filter($m[0], fn (string $amount) => $tableText === '' || ! str_contains($tableText, $amount)));
        if ($foreign === []) {
            return Verdict::allow();
        }

        $trace = $state->trace;
        $trace['floor_decision'] = [
            'path' => 'agent_figure_guard',
            'outcome' => 'escalate',
            'escalation_reason' => 'low_confidence',
            'authority_used' => [],
            'note' => 'figure_not_from_table — synthesised prose contained a euro amount not produced by salary_lookup this turn',
        ];
        $trace['agent']['figure_not_from_table'] = ['sub_outcome' => 'figure_not_from_table', 'amounts' => array_slice($foreign, 0, 5)];

        $outcome = new TurnOutcome('escalate', ChatService::EMPLOYEE_ESCALATION_MESSAGE, [], $trace, 'low_confidence');

        return Verdict::forceEscalate($outcome, $this->id());
    }

    /** Answer text any `salary_lookup` result produced this turn (empty when none did). */
    private function salaryLookupText(TurnState $state): string
    {
        $salary = $state->material['salary_lookup'] ?? null;

        return $salary instanceof ToolResult && $salary->terminalOutcome instanceof TurnOutcome
            ? $salary->terminalOutcome->answer
            : '';
    }
}
