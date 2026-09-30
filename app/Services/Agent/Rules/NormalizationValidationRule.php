<?php

namespace App\Services\Agent\Rules;

use App\Models\Convenio;
use App\Models\Territory;
use App\Models\Topic;
use App\Services\Agent\Normalization\NormalizationDiff;
use App\Services\Agent\Rule;
use App\Services\Agent\ToolResult;
use App\Services\Agent\TurnState;
use App\Services\Agent\Verdict;

/**
 * Sprint 13b (plan.md §3) — the `pre_call:normalize_question` rule. The planner's `normalize_question`
 * control call is never trusted: this rule runs {@see NormalizationDiff} over it and records the
 * outcome on `TurnState::$normalization`. `allow` = the proposal may be used by the tools this turn;
 * `deny` = the literal path, exactly as if it had never been proposed (no round is spent, the planner
 * is not told — the turn simply continues). Any error in here is a rejection (fail-safe).
 */
final class NormalizationValidationRule implements Rule
{
    public const VALIDATOR_VERSION = 'nd-1';

    public function __construct(private readonly NormalizationDiff $diff) {}

    public function id(): string
    {
        return 'normalization_validation';
    }

    public function evaluate(TurnState $state, ?array $call, ?ToolResult $result): Verdict
    {
        $input = is_array($call['input'] ?? null) ? $call['input'] : [];

        try {
            $topicId = $input['topic_id'] ?? null;
            $topicRow = null;
            if (is_int($topicId)) {
                $t = Topic::query()->find($topicId, ['id', 'name', 'status']);
                $topicRow = $t === null ? null : ['id' => (int) $t->id, 'name' => (string) $t->name, 'status' => (string) $t->status];
            }
            $offered = array_fill_keys(Topic::query()->where('status', 'approved')->pluck('id')->map(fn ($v) => (int) $v)->all(), true);
            $names = array_merge(Territory::query()->pluck('name')->all(), Convenio::query()->pluck('name')->all());

            $r = $this->diff->check(
                $state->question,
                $input,
                $topicRow,
                $offered,
                array_values(array_filter($names, 'is_string')),
                (float) config('hr.normalization.min_topic_confidence', 0.6),
            );
        } catch (\Throwable $e) {
            $r = [
                'verdict' => 'rejected',
                'rejections' => [['rule' => 'validator_error', 'span' => null]],
                'proposed' => [],
                'topic_used' => null,
                'topic_name' => null,
                'canonical_used' => null,
                'topic_dropped' => false,
            ];
        }

        $used = $r['verdict'] === 'accepted' ? ['topic_id' => $r['topic_used'], 'topic_name' => $r['topic_name'], 'canonical_query' => $r['canonical_used']] : null;
        $state->normalization = [
            'requested' => true,
            'literal' => $state->question,
            'proposed' => $r['proposed'],
            'verdict' => $r['verdict'],
            'rejections' => $r['rejections'],
            'used' => $used,
            'topic_dropped' => $r['topic_dropped'],
            'consumers' => [],
            'round1a' => null,
            'validator_version' => self::VALIDATOR_VERSION,
            'planner_prompt_version' => $state->trace['agent']['planner']['prompt_version'] ?? null,
        ];
        $state->recordStep([
            'type' => 'normalization',
            'verdict' => $r['verdict'],
            'topic' => $r['topic_name'] ?? ($r['proposed']['topic_id'] ?? null),
            'confidence' => $r['proposed']['confidence'] ?? null,
            'rejected_by' => $r['rejections'][0]['rule'] ?? null,
        ]);

        return $r['verdict'] === 'rejected'
            ? Verdict::deny('normalización descartada por el validador', $this->id())
            : Verdict::allow();
    }
}
