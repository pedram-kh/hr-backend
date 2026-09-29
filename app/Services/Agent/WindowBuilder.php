<?php

namespace App\Services\Agent;

use App\Models\ChatMessage;
use App\Models\ChatSession;

/**
 * Sprint 13, build step 5's own build-table row (plan.md §B.4.4, "R5") listed
 * `WindowBuilder` (+ `WindowBuilderTest`) as an `ask_employee`-adjacent
 * deliverable, but no such class was written while step 5 landed its five
 * tools — a real gap, of the pre-authorized "add it, record it" shape (the
 * standing instruction's own rule), caught here while wiring the REAL
 * planner in step 6, which is the first actual consumer of a window (§C.10:
 * "the window (§B.4.4)" is part of the planner's system prompt). Backfilled
 * now rather than worked around; recorded in review.md's step 5 section
 * alongside `PeriodSupportGuard`'s own backfill note.
 *
 * Builds the CURRENT session's conversation window ONLY (`session_id` already
 * restricts to one employee, one session — {@see SessionResolver}), the last
 * 3 prior EXCHANGES (a `user` message immediately followed by its `assistant`
 * reply):
 * - Excludes `hr_agent` messages structurally (the query itself never selects
 *   that role) — a human reply is not a source and must not steer routing.
 * - A user message is included VERBATIM, capped at 500 chars.
 * - An assistant turn is a STRUCTURED SUMMARY only (`{outcome, path,
 *   tools_used}`) — never its answer text, never citations/traces/cards —
 *   EXCEPT an `ask` turn, whose question text is included verbatim (the next
 *   user message in the window answers it, so the question itself is context,
 *   not an "answer").
 * - `message_ids` lists every included message's id — this is what
 *   `trace.agent.window` persists (content is never stored on the trace,
 *   §B.4.4's last bullet — only ids, since the content is reconstructible
 *   from the messages table and the trace must not duplicate PII/content).
 */
final class WindowBuilder
{
    public const MAX_EXCHANGES = 3;

    public const USER_TEXT_CAP = 500;

    /** @return array{exchanges:list<array<string,mixed>>, message_ids:list<int>} */
    public function build(ChatSession $session): array
    {
        // Structurally excludes `hr_agent` (§B.4.4) — fetch a generous window
        // (2x the exchange cap, +1 for an odd trailing `ask` with no reply
        // yet) then pair up from the front once ordered chronologically.
        $messages = ChatMessage::where('session_id', $session->id)
            ->whereIn('role', ['user', 'assistant'])
            ->with('trace')
            ->orderByDesc('id')
            ->limit((self::MAX_EXCHANGES * 2) + 1)
            ->get()
            ->reverse()
            ->values();

        $exchanges = [];
        $messageIds = [];
        $count = $messages->count();
        $i = 0;
        while ($i < $count) {
            $user = $messages[$i];
            if ($user->role !== 'user') {
                $i++;

                continue;
            }
            $assistant = $messages->get($i + 1);
            if ($assistant === null || $assistant->role !== 'assistant') {
                // A trailing user message with no reply yet (should not
                // happen mid-turn, but fail safe: skip rather than pair
                // wrongly) — never included as a half-exchange.
                $i++;

                continue;
            }

            $exchanges[] = $this->summarizeExchange($user, $assistant);
            $messageIds[] = $user->id;
            $messageIds[] = $assistant->id;
            $i += 2;
        }

        $exchanges = array_slice($exchanges, -self::MAX_EXCHANGES);
        $messageIds = array_slice($messageIds, -(self::MAX_EXCHANGES * 2));

        return ['exchanges' => $exchanges, 'message_ids' => $messageIds];
    }

    /**
     * Rebuild a window from ids already recorded on `trace.agent.window`
     * (`agent:replay`). Same summarizer as `build()`, but pinned to the
     * exact messages the original turn consulted — never the current
     * session's tail, which would include the turn being replayed.
     *
     * @param  list<int>  $messageIds
     * @return array{exchanges:list<array<string,mixed>>, message_ids:list<int>}
     */
    public function buildFromIds(array $messageIds): array
    {
        if ($messageIds === []) {
            return ['exchanges' => [], 'message_ids' => []];
        }

        $messages = ChatMessage::whereIn('id', $messageIds)
            ->whereIn('role', ['user', 'assistant'])
            ->with('trace')
            ->orderBy('id')
            ->get()
            ->values();

        $exchanges = [];
        $ids = [];
        $count = $messages->count();
        $i = 0;
        while ($i < $count) {
            $user = $messages[$i];
            if ($user->role !== 'user') {
                $i++;

                continue;
            }
            $assistant = $messages->get($i + 1);
            if ($assistant === null || $assistant->role !== 'assistant') {
                $i++;

                continue;
            }
            $exchanges[] = $this->summarizeExchange($user, $assistant);
            $ids[] = $user->id;
            $ids[] = $assistant->id;
            $i += 2;
        }

        return ['exchanges' => $exchanges, 'message_ids' => $ids];
    }

    /** @return array<string,mixed> */
    private function summarizeExchange(ChatMessage $user, ChatMessage $assistant): array
    {
        $trace = $assistant->trace?->trace ?? [];
        $outcome = $trace['floor_decision']['outcome'] ?? null;
        $path = $trace['floor_decision']['path'] ?? null;

        $steps = $trace['agent']['steps'] ?? [];
        $toolsUsed = [];
        foreach ($steps as $step) {
            if (($step['type'] ?? null) === 'tool_call' && is_string($step['tool'] ?? null)) {
                $toolsUsed[] = $step['tool'];
            }
        }
        $toolsUsed = array_values(array_unique($toolsUsed));

        $summary = [
            // §B.4.4: "user messages verbatim (each capped at 500 chars)".
            'user_question' => mb_substr((string) $user->content, 0, self::USER_TEXT_CAP),
            'outcome' => $outcome,
            'path' => $path,
            'tools_used' => $toolsUsed,
        ];

        // "for an ask turn, its question text verbatim (the next user message
        // answers it)" — never the plain answer text of any OTHER outcome.
        if ($outcome === 'ask') {
            $summary['question_text'] = (string) $assistant->content;
        }

        return $summary;
    }
}
