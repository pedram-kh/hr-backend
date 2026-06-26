<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\ReferenceFact;
use App\Models\Topic;
use App\Support\TopicLexicon;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * The deterministic reference-fact PRE-CHECK (Sprint 7c Phase 1, ADR-0023) — the
 * salary-pre-classifier's sibling. Runs in the same routing layer (after the
 * guardrail baseline, on a NON-salary question — Q4), BEFORE the LLM router.
 *
 * It answers one deterministic question, with NO LLM (ADR-0016): does the
 * employee's resolved scope (convenio + as-of) have a VERIFIED reference fact
 * whose topic matches this question's topic? Topic matching reuses the existing
 * {@see TopicLexicon} via the static anchor→approved-topic-name map (Q1) — no new
 * classifier, no alias/slug column on `topics`.
 *
 * FAIL-SAFE (ADR-0016): no anchor / no approved topic / no verified in-scope
 * in-validity fact / any error → return null (FALL THROUGH to the existing
 * router → prose/salary path, byte-for-byte unchanged). The pre-check ONLY ever
 * ADDS a route when a matching verified fact exists; it can never suppress or
 * alter today's routing. This is the additivity guarantee at the routing layer.
 *
 * It detects EXISTENCE only; the authoritative scope resolution + answer (and the
 * most-specific-else-escalate / two-match safe rules) live in
 * {@see ReferenceFactAnswerService}.
 */
class ReferenceFactRouter
{
    /**
     * Detect a routable reference-fact topic for this turn, or null to fall
     * through. Deterministic + fail-safe.
     *
     * @return array{topic_id:int, topic_name:string, matched_topic_names:list<string>}|null
     */
    public function detectTopic(Employee $employee, string $question, Carbon $asOfDate): ?array
    {
        try {
            $convenioId = $employee->convenio_id;
            if ($convenioId === null) {
                return null;
            }

            // Q1: map the question to approved-topic NAMES via the shared lexicon.
            $candidateNames = TopicLexicon::candidateTopicNames($question);
            if ($candidateNames === []) {
                return null; // no anchored topic → fall through safely
            }

            // Approved topics matching the candidate names (case-insensitive). The
            // topics table is tiny; an in-memory filter avoids raw SQL/lower().
            $topics = Topic::query()
                ->where('status', 'approved')
                ->get(['id', 'name'])
                ->filter(fn (Topic $t) => in_array(mb_strtolower($t->name), $candidateNames, true))
                ->values();

            if ($topics->isEmpty()) {
                return null; // anchored, but no approved topic exists → fall through
            }

            $asOf = $asOfDate->toDateString();
            foreach ($topics as $topic) {
                // EXISTENCE of a VERIFIED, in-scope, in-validity fact (Q3 validity).
                // Only `verified` ever makes the route reachable (ADR-0020/0021).
                $exists = ReferenceFact::query()
                    ->where('convenio_id', $convenioId)
                    ->where('topic_id', $topic->id)
                    ->where('status', 'verified')
                    ->where(fn ($q) => $q->whereNull('validity_start')->orWhere('validity_start', '<=', $asOf))
                    ->where(fn ($q) => $q->whereNull('validity_end')->orWhere('validity_end', '>=', $asOf))
                    ->exists();

                if ($exists) {
                    return [
                        'topic_id' => (int) $topic->id,
                        'topic_name' => (string) $topic->name,
                        'matched_topic_names' => $candidateNames,
                    ];
                }
            }

            return null; // anchored topic, but no verified in-scope in-validity fact → fall through
        } catch (\Throwable $e) {
            // FAIL-SAFE: never let a pre-check error change routing — fall through.
            Log::warning('reference-fact pre-check failed (fail-safe fall-through)', ['detail' => $e->getMessage()]);

            return null;
        }
    }
}
