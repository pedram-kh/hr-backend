<?php

namespace App\Services\Agent\Tools;

use App\Services\Agent\Rules\AskEmployeePostCallRule;
use App\Services\Agent\Rules\AskEmployeeWhitelist;
use App\Services\Agent\Tool;
use App\Services\Agent\ToolResult;
use App\Services\Agent\TurnState;
use App\Services\Answer\TurnOutcome;

/**
 * Sprint 13, build step 5 (plan.md §B.4) — `ask_employee`. By the time
 * `run()` executes, `Rules\AskEmployeeWhitelist` (`pre_call:ask_employee`)
 * has already denied or force-escalated every violation (§B.4.1's ordered
 * checks) and the generic `pre_call` budget rule has already confirmed the
 * per-conversation counter has room (§B.4.3) — so `run()` itself has
 * nothing left to validate; it just builds the `ask` outcome §B.4.2
 * describes verbatim (no citations, no card — `TurnPersister::persist()`
 * only cards/overrides on `outcome === 'escalate'`). The
 * {@see AskEmployeePostCallRule} then always
 * forces it, the same two-step shape `SalaryLookupPostCallRule` uses for
 * `needs_category` — one clarification family, §B.4.3.
 */
final class AskEmployeeTool implements Tool
{
    public function name(): string
    {
        return 'ask_employee';
    }

    public function definition(): array
    {
        return [
            'name' => 'ask_employee',
            'description' => 'Haz UNA pregunta aclaratoria breve cuando no sepas cuál de varias '
                .'subpreguntas quiere la persona o a qué año/periodo se refiere. Máximo dos por '
                .'conversación. NUNCA preguntes por grupo profesional, convenio, provincia, antigüedad, '
                .'tipo de contrato ni lo que cobra: esos datos vienen del Directorio; si faltan, usa escalate.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'topic' => ['type' => 'string', 'enum' => AskEmployeeWhitelist::ALLOWED_TOPICS],
                    'question' => ['type' => 'string', 'maxLength' => 200],
                ],
                'required' => ['topic', 'question'],
                'additionalProperties' => false,
            ],
        ];
    }

    public function countsAsClarification(): bool
    {
        return true;
    }

    public function run(array $input, TurnState $state): ToolResult
    {
        $question = is_string($input['question'] ?? null) ? $input['question'] : '';

        $trace = $state->trace;
        $trace['floor_decision'] = [
            'path' => 'agent_ask_employee',
            'outcome' => 'ask',
            'escalation_reason' => null,
            'authority_used' => [],
            'note' => 'ask_employee: '.($input['topic'] ?? '?'),
        ];

        $outcome = new TurnOutcome('ask', $question, [], $trace, null);

        return new ToolResult(ToolResult::TERMINAL, terminalOutcome: $outcome, plannerSummary: ['status' => 'ask']);
    }
}
