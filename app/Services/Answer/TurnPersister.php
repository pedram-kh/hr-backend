<?php

namespace App\Services\Answer;

use App\Jobs\GenerateEscalationExplanationText;
use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Models\Employee;
use App\Models\EscalationCard;
use App\Models\MessageCitation;
use App\Models\MessageTrace;
use App\Services\ChatService;
use App\Services\Decline\DeclineGate;
use App\Services\GuardrailPolicy;
use App\Support\EscalationExplainer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Sprint 13, build step 1 (plan.md §B.1) — `App\Services\Answer\TurnPersister`,
 * extracted VERBATIM from `ChatService::persistTurn()` + `ChatService::decorate()`
 * (`ChatService.php:1737-1861`, pre-refactor line numbers). Both the classic
 * engine and (later) the agent engine persist through this ONE place, so the
 * one-place escalation override (ADR-0029) and the answer-side decoration
 * (Sprint 10a) keep holding for both.
 *
 * No condition, constant, or trace key was edited in this move — see
 * `ChatService` for the constants referenced below (`ESCALATION_MESSAGE`,
 * `EMPLOYEE_ESCALATION_MESSAGE`, `FALLBACK_ESTATUTO_GAP`, `FALLBACK_CAVEAT`),
 * left in place rather than duplicated or relocated.
 */
class TurnPersister
{
    /**
     * The mirror of the escalation override, for the ANSWER side (Sprint 10a).
     *
     * `persist()` is already the one place an employee-visible escalation
     * string can originate from (Sprint 7g Item 1, ADR-0029). This makes it the
     * one place an employee-visible answer can be decorated, for the same
     * reason: `$employeeAnswer` is what gets persisted AND returned, so a caveat
     * added anywhere else could be shown without being stored, or stored without
     * being shown.
     *
     * Placement is what makes this safe. It runs after synthesis and after every
     * gate — Check A, Check B, the figure-guard and `/ground` have all already
     * passed on the UNDECORATED answer. So the caveat cannot be mistaken for a
     * model claim, cannot be sent to `/ground` as one, and cannot change any
     * gate's verdict. Deterministic: a fixed constant appended on a boolean
     * read off the trace, with no model in the loop (ADR-0015/0016).
     *
     * @param  array<string,mixed>  $trace
     */
    private static function decorate(string $answer, array $trace): string
    {
        if (($trace['floor_decision']['fallback'] ?? null) === ChatService::FALLBACK_ESTATUTO_GAP) {
            return $answer.ChatService::FALLBACK_CAVEAT;
        }

        // Sprint 13, step 9 (plan.md §B.6.6) — the general-lane badge caveat,
        // same placement/discipline as the fallback caveat above (after
        // synthesis, after grounding, after `GeneralLanePostCheck` — never a
        // model claim, never checked by `/ground`).
        if (($trace['floor_decision']['path'] ?? null) === ChatService::GENERAL_LANE_PATH) {
            // Slice 13c: the model-knowledge basis carries its own caveat (no page was consulted); web keeps today's.
            return $answer.(($trace['general_lane']['basis'] ?? null) === 'model_knowledge'
                ? ChatService::GENERAL_LANE_MODEL_CAVEAT
                : ChatService::GENERAL_LANE_CAVEAT);
        }

        return $answer;
    }

    /**
     * Slice 13e, L2 — why a decline is NOT valid (empty list = valid). Reads only the trace the turn will be stored with, so
     * a code path that builds a decline some other way is caught here, not trusted.
     *
     * @param  array<string,mixed>  $trace
     * @return list<string>
     */
    public static function declineViolations(TurnOutcome $outcome): array
    {
        $trace = $outcome->trace;
        $d = $trace['decline'] ?? null;
        $v = [];
        if ($outcome->declineGrant === null) {
            $v[] = 'no_grant';
        }
        if (! is_array($d)) {
            return [...$v, 'no_decline_block'];
        }
        if (($d['reason'] ?? null) !== DeclineGate::ONLY_REASON) {
            $v[] = 'reason:'.(is_scalar($d['reason'] ?? null) ? (string) $d['reason'] : '?');
        }
        if (($d['granted'] ?? null) !== true) {
            $v[] = 'not_granted';
        }
        if (! in_array($d['source'] ?? null, ['planner', 'guard_admin'], true)) {
            $v[] = 'unknown_source';
        }
        $checks = $d['checks'] ?? [];
        if (! is_array($checks) || $checks === []) {
            $v[] = 'no_checks';
        } else {
            foreach ($checks as $c) {
                if (($c['pass'] ?? null) !== true) {
                    $v[] = 'check_failed:'.(is_scalar($c['id'] ?? null) ? (string) $c['id'] : '?');
                }
            }
        }
        $fd = $trace['floor_decision'] ?? [];
        if (($fd['outcome'] ?? null) !== 'decline' || ($fd['decline_reason'] ?? null) !== DeclineGate::ONLY_REASON) {
            $v[] = 'floor_decision_mismatch';
        }
        if (! empty($fd['escalation_reason'])) {
            $v[] = 'carries_escalation_reason';
        }

        return $v;
    }

    /** An invalid decline is demoted to a `low_confidence` escalation ("more escalation, never less"); anything else passes through. */
    private function guardDecline(TurnOutcome $outcome, ChatSession $session): TurnOutcome
    {
        if ($outcome->outcome !== 'decline') {
            return $outcome;
        }
        $violations = self::declineViolations($outcome);
        if ($violations === []) {
            return $outcome;
        }

        Log::warning('decline_demoted', ['session_id' => $session->id, 'violations' => $violations]);
        $trace = $outcome->trace;
        unset($trace['floor_decision']['decline_reason']);
        $trace['floor_decision']['outcome'] = 'escalate';
        $trace['floor_decision']['escalation_reason'] = 'low_confidence';
        $trace['floor_decision']['note'] = 'decline demoted to escalation by the persister: '.implode(',', $violations);
        $trace['decline_demoted'] = $violations;

        return new TurnOutcome('escalate', ChatService::ESCALATION_MESSAGE, [], $trace, 'low_confidence');
    }

    /**
     * Persist the full turn in ONE transaction (hr-backend owns ALL writes):
     * user message, assistant message, citations (answer turns), trace (always),
     * escalation_card (escalate turns ONLY — never on a category pick). Returns
     * the response payload.
     *
     * @return array<string,mixed>
     */
    public function persist(ChatSession $session, Employee $employee, string $question, TurnOutcome $outcome): array
    {
        // Slice 13e (plan.md §4.2, L2) — fail-closed: a decline is re-validated from the trace AS WRITTEN. Anything that is
        // not a granted off_domain decision with every check passed becomes an ordinary escalation.
        $outcome = $this->guardDecline($outcome, $session);

        $answer = $outcome->answer;
        $citations = $outcome->citations;
        $trace = $outcome->trace;
        $outcomeLabel = $outcome->outcome;
        $escalationReason = $outcome->escalationReason;
        $categories = $outcome->categories;

        $escalate = $outcomeLabel === 'escalate';

        // Sprint 7g Item 1 (ADR-0029): the SINGLE override point. Every escalate
        // call site above still passes its own per-reason internal copy (kept as
        // call-site documentation of why THAT path escalates) — it is discarded
        // here and replaced with the one fixed neutral message, unconditionally,
        // regardless of $escalationReason. This is what the guard/scan test
        // relies on: there is exactly one place in the codebase an employee-
        // visible escalation string can originate from.
        // Slice 13e: a decline has its own single source of copy, decided here for the same reason (ADR-0039): the admin's
        // off-domain text, else the constant. It is never an escalation string and never carries a reason.
        $employeeAnswer = $escalate
            ? ChatService::EMPLOYEE_ESCALATION_MESSAGE
            : ($outcomeLabel === 'decline'
                ? (app(GuardrailPolicy::class)->offDomainMessage() ?? ChatService::DECLINE_MESSAGE)
                : self::decorate($answer, $trace));

        return DB::transaction(function () use ($session, $employee, $question, $employeeAnswer, $citations, $trace, $outcomeLabel, $escalate, $escalationReason, $categories) {
            $session->forceFill(['last_activity_at' => now()])->save();

            $userMessage = ChatMessage::create([
                'session_id' => $session->id,
                'role' => 'user',
                'content' => $question,
            ]);

            $assistantMessage = ChatMessage::create([
                'session_id' => $session->id,
                'role' => 'assistant',
                'content' => $employeeAnswer,
            ]);

            foreach ($citations as $c) {
                MessageCitation::create([
                    'message_id' => $assistantMessage->id,
                    'document_id' => $c['document_id'],
                    'chunk_id' => $c['chunk_id'] ?? null,
                    'page_number' => $c['page_number'] ?? null,
                ]);
            }

            MessageTrace::create([
                'message_id' => $assistantMessage->id,
                'trace' => $trace,
            ]);

            $card = null;
            if ($escalate) {
                $reason = $escalationReason ?? 'low_confidence';
                // Sprint 7g Item 1 (ADR-0029): the DETERMINISTIC facts are cheap
                // (a pure function over data already on $trace) and computed
                // SYNCHRONOUSLY, in the same transaction, so the card is never
                // seen by HR without them. The fix is always structured here —
                // never the model's — per the sprint constraint. The AI-written
                // paragraph ("Resumen IA") is a separate, ASYNCHRONOUS step
                // (dispatched after commit, below) so a provider call never
                // holds this transaction open.
                $explanation = EscalationExplainer::explain($reason, $trace);
                $card = EscalationCard::create([
                    'chat_session_id' => $session->id,
                    'source_message_id' => $userMessage->id,
                    'employee_id' => $employee->id,
                    'reason' => $reason,
                    'status' => 'new',
                    'explanation_facts' => $explanation,
                    'fix_action' => $explanation['fix_action'],
                    'fix_surface' => $explanation['fix_surface'],
                    'fix_link' => $explanation['fix_link'],
                ]);

                // The AI paragraph is a SEPARATE, asynchronous step — ->afterCommit()
                // (Laravel core) defers the actual dispatch until this transaction
                // commits, so the job never runs against a card row that isn't
                // visible yet, without hand-rolling a post-transaction hook here.
                GenerateEscalationExplanationText::dispatch($card->uuid)->afterCommit();
            }

            return [
                'session_uuid' => $session->uuid,
                'message_id' => $assistantMessage->id,
                'outcome' => $outcomeLabel,
                'escalated' => $escalate,
                'escalation_reason' => $escalationReason,
                'escalation_uuid' => $card?->uuid,
                'answer' => $employeeAnswer,
                'citations' => $outcomeLabel === 'answer' ? $citations : [],
                'categories' => $categories,
                'authority_used' => $trace['floor_decision']['authority_used'] ?? [],
                'trace' => $trace,
            ];
        });
    }
}
