<?php

namespace App\Console\Commands;

use App\Models\ChatMessage;
use App\Services\Agent\ControlTools;
use App\Services\Agent\PlannerClient;
use App\Services\Agent\PlannerUnavailableException;
use App\Services\Agent\ScopeSummaryBuilder;
use App\Services\Agent\ToolRegistry;
use App\Services\Agent\WindowBuilder;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Sprint 13, build step 6 (plan.md §C.10 / §E.15) — rebuild one planner
 * round from a persisted agent-turn trace, re-call `/plan`, print a
 * decision diff. Read-only: never persists, never writes a card or a
 * message. The 10b §9 lesson (a replay that wrote would pollute the
 * session).
 */
class AgentReplay extends Command
{
    protected $signature = 'agent:replay
        {message_id : the assistant chat_messages.id whose trace.agent.steps to replay}
        {--round=1 : which planner_round (1-based) to re-call}';

    protected $description = 'Re-call /plan for one recorded agent turn (read-only; prints a decision diff).';

    public function handle(
        PlannerClient $planner,
        ScopeSummaryBuilder $scopeSummary,
        WindowBuilder $windowBuilder,
        ToolRegistry $tools,
    ): int {
        $message = ChatMessage::with(['trace', 'session.employee'])->find($this->argument('message_id'));
        if ($message === null || $message->role !== 'assistant') {
            $this->error('No assistant message with that id.');

            return self::FAILURE;
        }

        $trace = $message->trace?->trace;
        if (! is_array($trace) || ($trace['engine'] ?? null) !== 'agent') {
            $this->error('That message is not an agent-engine turn (no trace.engine=agent).');

            return self::FAILURE;
        }

        $roundWanted = (int) $this->option('round');
        $steps = $trace['agent']['steps'] ?? [];
        $recorded = null;
        $prior = [];
        foreach ($steps as $step) {
            if (($step['type'] ?? null) === 'planner_round' && (int) ($step['round'] ?? 0) === $roundWanted) {
                $recorded = $step;
                break;
            }
            $prior[] = $step;
        }
        if ($recorded === null) {
            $this->error("No planner_round {$roundWanted} on this turn.");

            return self::FAILURE;
        }

        $session = $message->session;
        $employee = $session?->employee;
        if ($employee === null) {
            $this->error('Message has no session/employee — cannot rebuild the scope summary.');

            return self::FAILURE;
        }

        $user = ChatMessage::where('session_id', $session->id)
            ->where('role', 'user')
            ->where('id', '<', $message->id)
            ->orderByDesc('id')
            ->first();
        if ($user === null) {
            $this->error('No preceding user message — cannot rebuild the question.');

            return self::FAILURE;
        }

        $definitions = [...$tools->definitions(), ...ControlTools::definitions()];

        $this->info("Replaying message {$message->id}, planner_round {$roundWanted} (read-only).");
        $this->line('Recorded calls: '.json_encode($recorded['calls'] ?? [], JSON_UNESCAPED_UNICODE));

        try {
            $asOf = isset($trace['scope_filters']['as_of_date'])
            ? Carbon::parse($trace['scope_filters']['as_of_date'])
            : Carbon::today();
            $fresh = $planner->plan(
                $user->content,
                $scopeSummary->build($employee, $asOf),
                $windowBuilder->buildFromIds($trace['agent']['window']['message_ids'] ?? []),
                $definitions,
                $prior,
            );
        } catch (PlannerUnavailableException $e) {
            $this->error('Replay /plan failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->line('Fresh calls:    '.json_encode($fresh['calls'] ?? [], JSON_UNESCAPED_UNICODE));
        $this->line('prompt_version recorded='.($recorded['prompt_version'] ?? '—').' fresh='.($fresh['prompt_version'] ?? '—'));

        $same = ($recorded['calls'] ?? null) === ($fresh['calls'] ?? null);
        $this->info($same ? 'Decision: IDENTICAL tool calls.' : 'Decision: DIFFERS (Anthropic tool-use is not bit-exact; the record is the source of truth).');

        return self::SUCCESS;
    }
}
