<?php

namespace App\Services;

use App\Models\Document;
use App\Models\DocumentReviewTask;
use Throwable;

/**
 * Sprint 7d (ADR-0024), part C — PROPOSE a successor for an expiring document.
 *
 * 7a built the expiry queue and the human-confirmed lineage write-side, and left
 * the *suggestion* to 7d because it needs semantic comparison. This service makes
 * the suggestion and does nothing else with it.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * THE PROPOSAL IS INERT (ADR-0020). This service writes exactly three columns on
 * the review TASK: `ai_proposal`, `ai_proposal_status`, `ai_proposed_at`. It never
 * writes `documents.predecessor_document_id`, never `documents.retrieval_status`,
 * never `documents.validity_*`, never any `documents` column at all. A human
 * clicking Confirm runs the UNCHANGED 7a write-side, where same-convenio is
 * enforced independently and the predecessor is still never auto-retired.
 * ────────────────────────────────────────────────────────────────────────────
 *
 * NO LLM — AND THAT IS THE SAFETY ARGUMENT, NOT A COST SAVING.
 * The relationship is derived from two INDEPENDENT signals:
 *
 *   successor          overlap ≥ threshold  AND  candidate starts strictly later
 *   conflict           overlap ≥ threshold  AND  NOT strictly later
 *   coexisting_sibling overlap ≤ sibling ceiling (same convenio, different subject)
 *   uncertain          anything in between, or too little text to judge
 *
 * `successor` is the one label that would tempt a human to RETIRE a live document
 * — the exact harm 7a's "never auto-retire" rule exists to prevent. Putting it
 * behind a CONJUNCTION means a model's similarity opinion alone can never produce
 * it: the validity dates, which are registry facts and not inferences, must agree.
 * A confidently-wrong successor therefore needs two independent things to be wrong
 * at once.
 *
 * The honest cost, stated: a document that CONTRADICTS the expiring one without
 * being a version comes out `conflict` with no explanation of what contradicts —
 * the human reads the compared passages. For a queue whose only job is to route to
 * a human, that is the correct conservative outcome. It also means (C) needs no
 * answer-model key configured, so it works in an environment where 7a/7b-2's
 * proposers skip entirely.
 *
 * It is still an `ai_agent` proposal and still gets the fuchsia inert treatment:
 * BGE-M3 similarity is an AI inference even without an LLM, and the provenance
 * rule is about whether a machine made the claim, not which kind of machine.
 */
class SuccessionProposalService
{
    public const SUCCESSOR = 'successor';

    public const CONFLICT = 'conflict';

    public const SIBLING = 'coexisting_sibling';

    public const UNCERTAIN = 'uncertain';

    public function __construct(private readonly ExtractionClient $ai) {}

    /**
     * Compare the expiring document against its same-convenio siblings and write
     * one inert proposal on the task.
     *
     * @return array<string,mixed> a summary for the log (never thrown from the job)
     */
    public function propose(DocumentReviewTask $task): array
    {
        $expiring = $task->document;
        $computed = $this->compute($expiring);

        if ($computed['proposal'] === null) {
            return $this->storeNothing($task, (string) $computed['reason']);
        }

        $task->forceFill([
            'ai_proposal' => $computed['proposal'],
            'ai_proposal_status' => DocumentReviewTask::PROPOSAL_PROPOSED,
            'ai_proposed_at' => now(),
        ])->save();

        return [
            'task_id' => $task->id,
            'relationship' => $computed['proposal']['relationship'],
            'candidate_document_id' => $computed['proposal']['candidate_document_id'],
            'max_score' => $computed['proposal']['max_score'],
        ];
    }

    /**
     * The whole proposal computation, WITHOUT writing anything. `propose()` persists
     * what this returns; `succession:gold-eval` calls it directly so the eval can
     * measure the real classifier on the real corpus without creating review tasks
     * or touching a single row.
     *
     * @return array{proposal: array<string,mixed>|null, reason: string|null, scored: array<int,float>}
     */
    public function compute(?Document $expiring): array
    {
        if ($expiring === null || $expiring->convenio_id === null) {
            // An unscoped document has no same-convenio candidate set, and succession
            // is scope-based. Nothing is proposed — deliberately, not as a failure.
            return $this->nothing('expiring document is unscoped (no convenio) — succession is scope-based');
        }

        // SAME CONVENIO ONLY, and the same candidate set + cap the 7a read side
        // already offers the human (`ReviewQueueController::expiry`), so the proposal
        // can never suggest something the confirm form cannot accept.
        $candidates = Document::query()
            ->where('convenio_id', $expiring->convenio_id)
            ->where('id', '!=', $expiring->id)
            ->orderByDesc('validity_start')
            ->limit((int) config('hr.succession_candidate_max'))
            ->get(['id', 'uuid', 'title', 'validity_start', 'validity_end', 'retrieval_status']);

        if ($candidates->isEmpty()) {
            return $this->nothing('no other document in this convenio — nothing to compare against');
        }

        try {
            // The expiring document's OWN chunk texts are the probes (read hr-ai-side
            // from `document_ids`); the candidates are the ranked side. Read-only.
            $result = $this->ai->compareScope([
                'document_ids' => [$expiring->id],
                'probe_limit' => (int) config('hr.succession_probe_max'),
                'convenio_id' => $expiring->convenio_id,
                // Any authority level a same-convenio sibling may carry. The band is
                // still applied in SQL, and `candidate_document_ids` pins the set.
                'authority_levels' => ['official_convenio', 'internal_hr_ruling', 'partial_agreement', 'national_law'],
                'candidate_document_ids' => $candidates->pluck('id')->map(fn ($id) => (int) $id)->all(),
                'retrieval_status' => [],
                'as_of_date' => null,
                'exclude_document_ids' => [$expiring->id],
                'k' => 5,
            ]);
        } catch (Throwable $e) {
            // No proposal is not a failure state for the queue: the expiry task is
            // still there and the human can still act. Recorded, never guessed at.
            return $this->nothing('comparison unavailable: '.$e->getMessage());
        }

        // Best score per candidate document, plus the passage pair that produced it.
        $best = [];
        foreach ($result['matches'] ?? [] as $match) {
            foreach ($match['chunks'] ?? [] as $chunk) {
                $docId = (int) $chunk['document_id'];
                $score = (float) $chunk['score'];
                if (! isset($best[$docId]) || $score > $best[$docId]['score']) {
                    $best[$docId] = [
                        'score' => $score,
                        'expiring_excerpt' => mb_substr((string) ($match['probe_excerpt'] ?? ''), 0, 400),
                        'expiring_chunk_id' => $match['probe_source']['chunk_id'] ?? null,
                        'candidate_chunk_id' => (int) $chunk['id'],
                        'candidate_excerpt' => mb_substr((string) ($chunk['content'] ?? ''), 0, 400),
                    ];
                }
                // Keep a running top-3 mean per document for the reviewer's context.
                $best[$docId]['scores'][] = $score;
            }
        }

        if ($best === []) {
            return $this->nothing('the expiring document has no comparable chunks (no text layer?) — nothing proposed');
        }

        // The single best candidate is the one proposed. Proposing a list would push
        // the choice back onto the human without helping them; proposing one, with
        // the passages that justify it, is a claim they can check.
        uasort($best, fn (array $a, array $b) => $b['score'] <=> $a['score']);
        $topId = (int) array_key_first($best);
        $top = $best[$topId];
        $candidate = $candidates->firstWhere('id', $topId);

        [$relationship, $uncertainty] = $this->relationship($expiring, $candidate, $top['score']);

        $scores = $top['scores'] ?? [$top['score']];
        rsort($scores);
        $topThree = array_slice($scores, 0, 3);

        $proposal = [
            'relationship' => $relationship,
            'candidate_document_id' => $candidate->id,
            'candidate_document_uuid' => (string) $candidate->uuid,
            'candidate_title' => $candidate->title,
            'candidate_retrieval_status' => $candidate->retrieval_status,
            'max_score' => round($top['score'], 6),
            'mean_top3' => round(array_sum($topThree) / count($topThree), 6),
            'thresholds' => [
                'overlap' => (float) config('hr.succession_overlap_threshold'),
                'sibling_ceiling' => (float) config('hr.succession_sibling_ceiling'),
            ],
            'validity' => [
                'expiring' => [$expiring->validity_start?->toDateString(), $expiring->validity_end?->toDateString()],
                'candidate' => [$candidate->validity_start?->toDateString(), $candidate->validity_end?->toDateString()],
            ],
            'passages' => [[
                'expiring_chunk_id' => $top['expiring_chunk_id'],
                'candidate_chunk_id' => $top['candidate_chunk_id'],
                'expiring_excerpt' => $top['expiring_excerpt'],
                'candidate_excerpt' => $top['candidate_excerpt'],
                'score' => round($top['score'], 6),
            ]],
            'uncertainty' => $uncertainty,
            'probe_count' => (int) ($result['probe_count'] ?? 0),
            'eligible_total' => (int) ($result['eligible_total'] ?? 0),
            'proposed_at' => now()->toIso8601String(),
            // Provenance: an AI inference (BGE-M3 similarity), so it is marked as one
            // and rendered fuchsia until a human confirms (ADR-0020).
            'source' => 'ai_agent',
        ];

        return [
            'proposal' => $proposal,
            'reason' => null,
            // Every candidate's best score, for the eval and for `--json` reporting.
            'scored' => array_map(fn (array $b) => round($b['score'], 6), $best),
        ];
    }

    /**
     * The deterministic relationship rule. Returns [relationship, uncertainty].
     *
     * Public because the gold eval measures exactly this function on labeled real
     * corpus pairs — the thing under test must be the thing that runs.
     *
     * @return array{0:string, 1:array<string,string>|null}
     */
    public function relationship(Document $expiring, Document $candidate, float $score): array
    {
        $overlap = (float) config('hr.succession_overlap_threshold');
        $ceiling = (float) config('hr.succession_sibling_ceiling');

        $strictlyLater = $candidate->validity_start !== null
            && $expiring->validity_start !== null
            && $candidate->validity_start->greaterThan($expiring->validity_start);

        if ($score >= $overlap) {
            if ($strictlyLater) {
                // BOTH conditions met — the only path to `successor`.
                return [self::SUCCESSOR, null];
            }

            return [self::CONFLICT, [
                'field' => 'relationship',
                'reason' => 'Mismo ámbito y contenido muy similar, pero las fechas de vigencia no establecen '
                    .'que sea una versión posterior. Puede ser un conflicto o una versión mal fechada — revísalo.',
            ]];
        }

        if ($score <= $ceiling) {
            return [self::SIBLING, [
                'field' => 'relationship',
                'reason' => 'Mismo convenio pero contenido distinto: probablemente documentos complementarios '
                    .'que coexisten, no una sucesión.',
            ]];
        }

        return [self::UNCERTAIN, [
            'field' => 'relationship',
            'reason' => sprintf(
                'Solapamiento intermedio (%.3f, entre %.2f y %.2f): no se afirma ninguna relación. Revisa los pasajes.',
                $score, $ceiling, $overlap,
            ),
        ]];
    }

    /**
     * "Nothing proposed, and here is why" — the computed form.
     *
     * @return array{proposal: null, reason: string, scored: array<int,float>}
     */
    private function nothing(string $reason): array
    {
        return ['proposal' => null, 'reason' => $reason, 'scored' => []];
    }

    /**
     * Record that nothing could be proposed, with the reason. `ai_proposal_status`
     * stays NULL — there is no proposal to confirm or reject — but the reason is
     * stored so the UI can say "no suggestion, and here is why" instead of showing
     * an empty box the human has to interpret.
     *
     * @return array<string,mixed>
     */
    private function storeNothing(DocumentReviewTask $task, string $reason): array
    {
        $task->forceFill([
            'ai_proposal' => ['relationship' => null, 'reason' => $reason, 'proposed_at' => now()->toIso8601String(), 'source' => 'ai_agent'],
            'ai_proposal_status' => null,
            'ai_proposed_at' => now(),
        ])->save();

        return ['task_id' => $task->id, 'relationship' => null, 'reason' => $reason];
    }
}
