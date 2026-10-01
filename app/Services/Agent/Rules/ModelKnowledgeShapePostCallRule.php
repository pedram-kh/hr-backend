<?php

namespace App\Services\Agent\Rules;

use App\Services\Agent\Rule;
use App\Services\Agent\ToolResult;
use App\Services\Agent\TurnState;
use App\Services\Agent\Verdict;
use App\Services\Answer\TurnOutcome;
use App\Services\ChatService;

/**
 * Slice 13c (plan.md §2.4) — `post_call:general_knowledge`, registered BETWEEN {@see GeneralLanePostCheck} and
 * {@see GeneralLaneFinishRule} (`RuleEngine::run()` stops at the first non-allow verdict, so a draft only reaches this rule
 * after the post-check has let it through, and the finish rule only sees what this rule let through). Applies
 * {@see ModelKnowledgeShapeCheck}; a hit discards the draft and force-escalates `general_lane_blocked`, the same reason the
 * post-check uses, with the S-rule ids on `trace.general_lane.shape`.
 */
final class ModelKnowledgeShapePostCallRule implements Rule
{
    public function id(): string
    {
        return 'general_lane_shape_check';
    }

    public function evaluate(TurnState $state, ?array $call, ?ToolResult $result): Verdict
    {
        if (! $result instanceof ToolResult || ! $result->terminalOutcome instanceof TurnOutcome || $result->terminalOutcome->outcome !== 'answer') {
            return Verdict::allow();
        }

        $lane = $result->terminalOutcome->trace['general_lane'] ?? [];
        $basis = ($lane['basis'] ?? null) === ModelKnowledgeShapeCheck::BASIS_MODEL ? ModelKnowledgeShapeCheck::BASIS_MODEL : ModelKnowledgeShapeCheck::BASIS_WEB;

        $shape = ModelKnowledgeShapeCheck::check($result->terminalOutcome->answer, $basis);
        if ($shape['verdict'] === 'pass') {
            return Verdict::allow();
        }

        $trace = $result->terminalOutcome->trace;
        $first = $shape['rule_ids'][0];
        $trace['floor_decision'] = [
            'path' => 'general_knowledge',
            'outcome' => 'escalate',
            'escalation_reason' => 'general_lane_blocked',
            'authority_used' => [],
            'note' => "general lane answer discarded — shape check hit {$first}",
        ];
        $trace['general_lane'] = array_merge($lane, ['shape' => ['verdict' => 'blocked', 'rule_ids' => $shape['rule_ids'], 'hits' => $shape['hits'], 'word_count' => $shape['word_count']]]);

        $trace['agent']['general_lane_blocked'] = ['sub' => 'shape'];

        return Verdict::forceEscalate(
            new TurnOutcome('escalate', ChatService::EMPLOYEE_ESCALATION_MESSAGE, [], $trace, 'general_lane_blocked'),
            $this->id(),
        );
    }
}
