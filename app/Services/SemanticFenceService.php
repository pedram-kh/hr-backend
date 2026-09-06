<?php

namespace App\Services;

use App\Models\Document;
use App\Support\SemanticComparison;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The semantic publish fence (Sprint 7d, ADR-0024) — the meaning-based half of
 * the no-override gate.
 *
 * WHAT IT IS. `EscalationService::detectConflicts` asks a structural question:
 * "is there an active official convenio in this scope that shares the ruling's
 * topic (or is untagged)?" This service asks the substantive one: "does the
 * official text in this scope already address this specific point?" — by
 * embedding the ruling's own words and ranking the scope's official-convenio
 * chunks against them (hr-ai `POST /compare-scope`, read-only).
 *
 * ────────────────────────────────────────────────────────────────────────────
 * WHAT IT IS *NOT*: A REPLACEMENT.
 * `fence = existing_block OR semantic_block`. This service is only ever
 * CONSULTED, and only when `detectConflicts` came back empty. It cannot clear a
 * structural block, because it is never asked when one exists. A similarity miss
 * can therefore never open a gate the crude check closed. Proven by
 * Sprint7dFenceNeverOpensTest (7 cases).
 * ────────────────────────────────────────────────────────────────────────────
 *
 * THREE THINGS THAT WOULD EACH BE A SILENT FAIL-OPEN, AND WHAT IS DONE INSTEAD:
 *
 *  1. **Authority filtered after the top-k.** `/retrieve` has no
 *     `authority_level` filter, so a caller must filter its results — and other
 *     same-convenio chunks (published rulings, partial agreements) can crowd the
 *     one overlapping convenio passage out of the top-k, leaving the fence to
 *     conclude "no conflict". → `/compare-scope` filters authority IN THE SQL, so
 *     the top score belongs to an exactly-filtered set and the decision is
 *     k-independent.
 *  2. **A long ruling silently truncated by the embedder.** `resolution_text` may
 *     be 20 000 chars; BGE-M3 truncates at its sequence limit, so the tail would
 *     never be compared. → the text is split into paragraph-sized PROBES that
 *     cover 100% of it, and the fence takes the MAX over all probes. More probes
 *     can only find more overlap, never less.
 *  3. **A failure read as a pass.** hr-ai down, or a scope whose only convenio is
 *     an unOCRd scan (0 chunks — the 7e gap). → both produce ACKNOWLEDGE, never
 *     CLEAR (see SemanticComparison's constructors: there is no code path from a
 *     failure to a clear result).
 *
 * hr-ai READS and RETURNS; this service decides nothing about retrievability and
 * writes nothing. The band decision lives in SemanticComparison, once.
 */
class SemanticFenceService
{
    /**
     * The authority band a ruling can override, and therefore the only band that
     * may block. `national_law` is deliberately ABSENT: the Estatuto is the
     * universal baseline, not a same-scope override target — every ruling
     * paraphrases it to some degree, so blocking on it would block everything
     * (and §8.3's rule is about the *convenio* governing the asker's scope).
     */
    private const BLOCKING_AUTHORITY = ['official_convenio'];

    public function __construct(private readonly ExtractionClient $ai) {}

    /**
     * Compare a draft ruling's text against the active official-convenio text in
     * the asker's scope.
     *
     * Called ONLY when the Sprint-4 structural fence found nothing (see the class
     * docblock). Never throws: a failure becomes an ACKNOWLEDGE result.
     */
    public function compareRulingToScope(
        int $convenioId,
        string $resolutionText,
        ?int $excludeDocumentId = null,
    ): SemanticComparison {
        $threshold = (float) config('hr.semantic_conflict_threshold');
        $reviewBand = (float) config('hr.semantic_review_band');

        // The candidate documents, straight from the registry (the system of
        // record): active `official_convenio` in this convenio. This is EXACTLY
        // `detectConflicts`'s universe minus its topic narrowing — a superset, so
        // the semantic pass can never consider less than the structural one did.
        $candidateIds = $this->activeOfficialConvenioIds($convenioId, $excludeDocumentId);

        if ($candidateIds === []) {
            // Nothing in scope could be overridden — and this is decided from the
            // registry, so no network call is made. A convenio-less scope must not
            // become unpublishable just because hr-ai is down.
            return SemanticComparison::nothingInScope($threshold, $reviewBand);
        }

        $probes = self::probes($resolutionText);
        if ($probes === []) {
            // Nothing to compare FROM, but there IS governing text: not evidence of
            // no conflict. (The controller requires non-empty resolution_text, so
            // this is a defensive branch.)
            return SemanticComparison::nothingComparable($threshold, $reviewBand, count($candidateIds));
        }

        try {
            $result = $this->ai->compareScope([
                'texts' => $probes,
                'convenio_id' => $convenioId,
                'authority_levels' => self::BLOCKING_AUTHORITY,
                // The registry's exact candidate list, filtered IN THE SQL (so the
                // top score is still k-independent). Chunk-level status/validity are
                // deliberately NOT filtered: those denormalized columns are only
                // refreshed on re-embed and go stale after a lifecycle edit, and
                // narrowing on a stale copy could hide a governing passage.
                'candidate_document_ids' => $candidateIds,
                'retrieval_status' => [],
                'as_of_date' => null,
                'exclude_document_ids' => $excludeDocumentId !== null ? [$excludeDocumentId] : [],
                'k' => (int) config('hr.semantic_compare_k'),
            ]);
        } catch (Throwable $e) {
            // FAIL TOWARD CAUTION. Never a clean publish on a failed comparison.
            Log::warning('semantic fence: comparison unavailable (falling back to human acknowledgement)', [
                'convenio_id' => $convenioId,
                'error' => $e->getMessage(),
            ]);

            return SemanticComparison::unavailable($threshold, $reviewBand, $e->getMessage());
        }

        $eligibleTotal = (int) ($result['eligible_total'] ?? 0);

        if ($eligibleTotal === 0) {
            // The governing convenio exists but contributed zero chunks — in
            // practice an unOCRd scan (the 7e gap; `deploy.md` §4a names live
            // instances). "I could not read the convenio" is NOT "the ruling does
            // not conflict with it", so this asks the human rather than passing.
            return SemanticComparison::nothingComparable($threshold, $reviewBand, count($candidateIds));
        }

        $maxScore = isset($result['max_score']) ? (float) $result['max_score'] : null;

        return SemanticComparison::measured(
            $maxScore,
            $this->flattenMatches($result['matches'] ?? [], $probes, min($reviewBand, $threshold)),
            (int) ($result['probe_count'] ?? count($probes)),
            $eligibleTotal,
            $threshold,
            $reviewBand,
        );
    }

    /**
     * The §8.5 reverse direction: compare a newly-active official convenio
     * against the published rulings in its own scope. Same primitive, probes and
     * candidates swapped — the convenio's chunks are the probes (read hr-ai-side
     * from `document_ids`, so chunk text hr-backend already stored is not shipped
     * back over the wire), the rulings are the candidate band.
     *
     * FLAG ONLY. The caller records a review task; nothing is demoted and
     * retrieval is never touched (2b precedence is frozen).
     */
    public function compareConvenioToRulings(Document $convenioDoc): SemanticComparison
    {
        $threshold = (float) config('hr.semantic_conflict_threshold');
        $reviewBand = (float) config('hr.semantic_review_band');

        if ($convenioDoc->convenio_id === null) {
            return SemanticComparison::nothingInScope($threshold, $reviewBand);
        }

        $rulingIds = Document::query()
            ->where('convenio_id', $convenioDoc->convenio_id)
            ->where('authority_level', 'internal_hr_ruling')
            ->where('retrieval_status', 'active')
            ->pluck('id')->map(fn ($id) => (int) $id)->all();

        if ($rulingIds === []) {
            return SemanticComparison::nothingInScope($threshold, $reviewBand);
        }

        try {
            $result = $this->ai->compareScope([
                'document_ids' => [$convenioDoc->id],
                'probe_limit' => (int) config('hr.semantic_probe_max'),
                'convenio_id' => $convenioDoc->convenio_id,
                'authority_levels' => ['internal_hr_ruling'],
                'candidate_document_ids' => $rulingIds,
                'retrieval_status' => [],
                'as_of_date' => null,
                'exclude_document_ids' => [$convenioDoc->id],
                'k' => (int) config('hr.semantic_compare_k'),
            ]);
        } catch (Throwable $e) {
            Log::warning('semantic reverse re-check: comparison unavailable', [
                'document_id' => $convenioDoc->id,
                'error' => $e->getMessage(),
            ]);

            return SemanticComparison::unavailable($threshold, $reviewBand, $e->getMessage());
        }

        if ((int) ($result['eligible_total'] ?? 0) === 0) {
            return SemanticComparison::nothingInScope($threshold, $reviewBand);
        }

        return SemanticComparison::measured(
            isset($result['max_score']) ? (float) $result['max_score'] : null,
            $this->flattenMatches($result['matches'] ?? [], [], $reviewBand),
            (int) ($result['probe_count'] ?? 0),
            (int) ($result['eligible_total'] ?? 0),
            $threshold,
            $reviewBand,
        );
    }

    /**
     * Split a ruling into paragraph-sized probes that COVER THE WHOLE TEXT.
     *
     * The coverage guarantee is the point (rule 2 in the class docblock): the
     * bucket size is `max(configured_max, ceil(total / probe_max))`, so however
     * long the ruling is, every character lands in some probe and the probe count
     * still respects the cap. Nothing is dropped — a dropped tail is a silent
     * fail-open, and this is a safety gate.
     *
     * Public + static so `fence:calibrate-semantic` measures the exact same
     * probes the live fence will use (a calibration on different inputs than
     * production would be worthless).
     *
     * @return list<string>
     */
    public static function probes(string $text): array
    {
        $max = max(1, (int) config('hr.semantic_probe_max'));
        $minChars = max(1, (int) config('hr.semantic_probe_min_chars'));
        $maxChars = max($minChars, (int) config('hr.semantic_probe_max_chars'));

        // Collapse runs of spaces/tabs but KEEP blank-line breaks: paragraphs are
        // the natural probe boundary in a ruling.
        $normalized = trim((string) preg_replace('/[ \t]+/u', ' ', $text));
        if ($normalized === '') {
            return [];
        }

        // Grow the bucket size until the packing fits inside the probe cap. The
        // cap must NEVER be enforced by dropping probes: a dropped probe is text
        // that was never compared — the exact silent fail-open this splitter
        // exists to prevent. So the bucket grows instead.
        $target = max($maxChars, (int) ceil(mb_strlen($normalized) / $max));
        $probes = [];
        for ($attempt = 0; $attempt < 8; $attempt++) {
            $probes = self::pack($normalized, $target, $minChars);
            if (count($probes) <= $max) {
                return $probes;
            }
            $target = (int) ceil($target * count($probes) / $max) + 1;
        }

        // Pathological input (should be unreachable): fold the overflow into the
        // last probe rather than lose it. A long final probe risks the embedder's
        // truncation; a missing final probe guarantees it.
        if (count($probes) > $max) {
            $tail = array_splice($probes, $max - 1);
            $probes[] = implode(' ', $tail);
        }

        return array_values($probes);
    }

    /**
     * Pack a normalized text into probes of at most ~$target chars, split on
     * paragraph then word boundaries. Every character of the input lands in
     * exactly one probe.
     *
     * @return list<string>
     */
    private static function pack(string $normalized, int $target, int $minChars): array
    {
        $pieces = [];
        foreach (preg_split('/\n\s*\n+/u', $normalized) ?: [] as $p) {
            $p = trim((string) preg_replace('/\s+/u', ' ', $p));
            if ($p === '') {
                continue;
            }
            // A single paragraph longer than the bucket is hard-split on word
            // boundaries — still full coverage, just more probes.
            while (mb_strlen($p) > $target) {
                $cut = mb_strrpos(mb_substr($p, 0, $target), ' ') ?: $target;
                $pieces[] = trim(mb_substr($p, 0, $cut));
                $p = trim(mb_substr($p, $cut));
            }
            if ($p !== '') {
                $pieces[] = $p;
            }
        }

        // Greedily merge adjacent pieces up to the bucket size, so a ruling of
        // many one-line paragraphs does not produce many near-empty probes.
        $probes = [];
        $buffer = '';
        foreach ($pieces as $piece) {
            $candidate = $buffer === '' ? $piece : $buffer.' '.$piece;
            if (mb_strlen($candidate) <= $target) {
                $buffer = $candidate;

                continue;
            }
            if ($buffer !== '') {
                $probes[] = $buffer;
            }
            $buffer = $piece;
        }
        if ($buffer !== '') {
            $probes[] = $buffer;
        }

        // A trailing scrap shorter than the floor is folded back rather than
        // dropped (dropping it would lose coverage of the ruling's last words).
        $count = count($probes);
        if ($count > 1 && mb_strlen($probes[$count - 1]) < $minChars) {
            $probes[$count - 2] = $probes[$count - 2].' '.$probes[$count - 1];
            array_pop($probes);
        }

        return array_values($probes);
    }

    /**
     * The active official convenio documents governing this scope — the SAME
     * candidate universe as `EscalationService::detectConflicts` (`:347-349`)
     * MINUS its topic narrowing. A deliberate superset: whatever the structural
     * fence considered, the semantic pass considers too, which is what makes the
     * combined fence unable to consider less than before.
     *
     * Read from `documents`, not from the chunk table's denormalized copy, because
     * that copy is only refreshed on re-embed (`updateLifecycle` changes
     * `documents.retrieval_status` without re-embedding) and the registry is the
     * system of record.
     *
     * @return list<int>
     */
    private function activeOfficialConvenioIds(int $convenioId, ?int $excludeDocumentId): array
    {
        return Document::query()
            ->where('convenio_id', $convenioId)
            ->where('authority_level', 'official_convenio')
            ->where('retrieval_status', 'active')
            ->when($excludeDocumentId !== null, fn ($q) => $q->where('id', '!=', $excludeDocumentId))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Flatten hr-ai's per-probe match lists into one score-ordered list of
     * passages for the human, resolving each chunk's document title/uuid (hr-ai
     * returns ids only — it never joins the registry).
     *
     * Only passages at/above the lower band are kept: the human is shown what is
     * plausibly relevant, not the whole ranking. The BLOCK DECISION IS NOT MADE
     * HERE — it is made from `max_score` in SemanticComparison, so this display
     * filter can never change an outcome.
     *
     * @param  list<array<string,mixed>>  $matches
     * @param  list<string>  $probes
     * @return list<array<string,mixed>>
     */
    private function flattenMatches(array $matches, array $probes, float $floor): array
    {
        $flat = [];
        $documentIds = [];
        foreach ($matches as $m) {
            foreach ($m['chunks'] ?? [] as $c) {
                if (($c['score'] ?? 0) < $floor) {
                    continue;
                }
                $documentIds[] = (int) $c['document_id'];
                $flat[] = [
                    'chunk_id' => (int) $c['id'],
                    'document_id' => (int) $c['document_id'],
                    'chunk_index' => $c['chunk_index'] ?? null,
                    'page_from' => $c['page_from'] ?? null,
                    'score' => (float) $c['score'],
                    'probe_index' => $m['probe_index'] ?? null,
                    'probe_excerpt' => $probes[$m['probe_index'] ?? -1] ?? ($m['probe_excerpt'] ?? null),
                    'excerpt' => mb_substr((string) ($c['content'] ?? ''), 0, 600),
                ];
            }
        }

        $titles = Document::whereIn('id', array_unique($documentIds))
            ->get(['id', 'uuid', 'title'])
            ->keyBy('id');

        foreach ($flat as &$row) {
            $doc = $titles->get($row['document_id']);
            $row['document_uuid'] = $doc?->uuid;
            $row['document_title'] = $doc?->title;
        }
        unset($row);

        usort($flat, fn ($a, $b) => $b['score'] <=> $a['score']);

        return array_values(array_slice($flat, 0, (int) config('hr.semantic_compare_k')));
    }
}
