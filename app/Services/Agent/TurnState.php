<?php

namespace App\Services\Agent;

use App\Models\ChatSession;
use App\Models\Employee;
use App\Services\Answer\TurnOutcome;
use Illuminate\Support\Carbon;

/**
 * Sprint 13, build step 3 (plan.md §D.11) — one turn's mutable state as it
 * moves through the loop: rounds/calls/asks used, the material collected so
 * far, the first forced verdict (if any), and the running `trace.agent.steps`
 * list. Passed BY REFERENCE (an object) to every {@see Rule} and {@see Tool}
 * — none of them may read the employee's answer text or citations before the
 * finisher runs (there are none yet to read); they read/write `$material`
 * and the trace/step bookkeeping only.
 */
final class TurnState
{
    public int $rounds = 0;

    public int $toolCalls = 0;

    public int $malformedCount = 0;

    public int $asksUsedBeforeTurn = 0;

    public int $asksThisTurn = 0;

    public bool $terminated = false;

    public ?string $terminationReason = null;

    public ?Verdict $forcedVerdict = null;

    public ?TurnOutcome $finalOutcome = null;

    /** @var list<array<string,mixed>> */
    public array $steps = [];

    /** @var array<string,ToolResult> keyed by tool name — the material a finisher (step 5) reads. */
    public array $material = [];

    /** @var array<string,ToolResult> identical-call cache, keyed by `tool:sha1(json(input))` (§C.9). */
    public array $cache = [];

    /**
     * Sprint 13, build step 6 (plan.md §B.4.4's last bullet) — the current
     * session's window message ids, IF `AgentChatService::loop()` actually
     * built one this turn (`[]` otherwise — a turn round 0 short-circuited
     * never consulted a window at all, so `[]` there is accurate). Ids only,
     * never content, per §B.4.4 — the trace must not duplicate PII/content
     * that already lives on the messages/trace rows themselves.
     *
     * @var list<int>
     */
    public array $windowMessageIds = [];

    public readonly float $startedAt;

    /**
     * @param  array<string,mixed>  $trace
     * @param  int|null  $selectedJobCategoryId  Sprint 13, build step 5 gap
     *                                           (found while wiring `salary_lookup`): classic's `needs_category` pick
     *                                           is a two-*request* flow — the employee's follow-up message carries
     *                                           `selected_job_category_id` (`ChatController.php:37`) back in on the
     *                                           NEXT `POST /chat/message`, and `SalaryPath::handle()` reads it as a
     *                                           plain constructor argument each time. The agent loop's `TurnState` is
     *                                           scoped to ONE turn, but `salary_lookup` can legitimately run in round
     *                                           1 as well as round 0 (a compound/follow-up question can defer the
     *                                           seed to the planner), so this needs to be on `TurnState` too — not
     *                                           threaded through every call site by hand. Additive; classic is
     *                                           unaffected (it never touches `TurnState`).
     */
    public function __construct(
        public readonly Employee $employee,
        public readonly string $question,
        public readonly Carbon $asOfDate,
        public readonly ChatSession $session,
        public array $trace,
        public readonly ?int $selectedJobCategoryId = null,
    ) {
        $this->startedAt = microtime(true);
    }

    public function wallClockSeconds(): float
    {
        return microtime(true) - $this->startedAt;
    }

    /** @param  array<string,mixed>  $step */
    public function recordStep(array $step): void
    {
        $this->steps[] = ['i' => count($this->steps), ...$step];
    }

    public function clarificationsUsed(): int
    {
        return $this->asksUsedBeforeTurn + $this->asksThisTurn;
    }

    /**
     * Apply a `Verdict::FORCE` — the ONE place `$terminated` becomes true.
     *
     * Sprint 13, build step 5: generalized to cover all three documented
     * force types, each of which now carries a fully-formed `TurnOutcome`
     * the rule/tool already built:
     * - `FORCE_ESCALATE` (`forcePayload->outcome === 'escalate'`);
     * - `FORCE_ASK` (`forcePayload->outcome ∈ {'ask','needs_category'}` — the
     *   ONE clarification family the §B.4.3 budget counts, hence one force
     *   type for both, not two);
     * - `FORCE_FINISH` — "the tool already fully decided this turn (e.g.
     *   `salary_lookup`'s plain `answer` outcome — a quoted figure needs no
     *   synthesis), terminate now" — distinct from `AgentChatService::finish()`,
     *   which is what runs the general prose/composition finisher on an
     *   explicit planner `finalize` call with NO forced verdict (§D.11); this
     *   is a tool bypassing that machinery entirely because it already has
     *   the complete answer.
     */
    public function applyForced(Verdict $verdict): void
    {
        $this->terminated = true;
        $this->forcedVerdict = $verdict;

        if (! $verdict->forcePayload instanceof TurnOutcome) {
            return;
        }

        $this->finalOutcome = $verdict->forcePayload;
        $this->terminationReason = match ($verdict->forceType) {
            Verdict::FORCE_ESCALATE => 'forced_escalation',
            Verdict::FORCE_ASK => $verdict->forcePayload->outcome, // 'ask' | 'needs_category'
            Verdict::FORCE_FINISH => 'forced_finish',
            default => null,
        };

        if ($verdict->forceType === Verdict::FORCE_ASK) {
            $this->asksThisTurn++;
        }
    }

    public static function cacheKey(string $tool, array $input): string
    {
        return $tool.':'.sha1(json_encode($input, JSON_UNESCAPED_UNICODE) ?: '');
    }
}
