<?php

namespace App\Services;

use App\Models\AnswerModelSetting;
use App\Models\Convenio;
use App\Models\Document;
use App\Models\ReferenceFact;
use App\Models\TagEvent;
use App\Models\Topic;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The AI segmentation tier — persist side (Sprint 7b-2, ADR-0022).
 *
 * hr-ai READS a reference_source's content and SEGMENTS it into per-scope facts;
 * THIS service is the only writer. It reuses the 7a TagProposalService posture
 * (build the closed candidate vocabulary → call hr-ai → persist as `ai_agent`
 * provenance, inert until a human verifies) and writes into the 7b-1
 * `reference_facts` table (the reserved `ai_agent` lane, now lit).
 *
 * The inherited invariants hold BY CONSTRUCTION (re-proven, not rebuilt):
 *  - INVARIANT 1 — authority can only be `structured_reference`: forced here,
 *    rejected at the column + the FormRequest. A fact never outranks a convenio.
 *  - inert-until-verified — every fact lands `status = needs_review`; the only
 *    writer of `verified` is the human ReferenceFactController::verify(). The
 *    agent NEVER verifies its own output.
 *  - routing rides `document_type` — this runs only for a `reference_source`
 *    document; it never writes a salary row (there is no code path to
 *    salary_table_rows).
 *  - bind-only (ADR-0011) — every convenio_id/job_category_id/topic_id was
 *    closed-set-validated hr-ai-side; nothing here mints vocabulary.
 *
 * Idempotency (Q1): UPSERT on the group_label-extended logical key
 *   (convenio_id, topic_id, job_category_id, group_label, validity_start,
 *    validity_end), scoped to source = ai_agent + this source document. Because
 * `convenio_job_categories` is salary-derived/unseeded, job_category_id is
 * usually null and `group_label` (the group AS WRITTEN) carries the per-group
 * identity so a file's many group facts don't self-clobber.
 *
 * Version (Q4): an obvious same-scope duplicate (a logical-key-scope collision
 * with a DIFFERING value across versions) is FLAGGED (duplicate_of_id +
 * uncertainty.field='version') — a signal only. Semantic conflict RESOLUTION is
 * Sprint 7d; nothing is merged, retired, or chosen here.
 */
class ReferenceFactProposalService
{
    public function __construct(private ExtractionClient $ai) {}

    /**
     * Segment one reference_source and persist its facts as inert AI proposals.
     *
     * @return array<string,mixed> a summary for the caller (job/endpoint)
     */
    public function propose(Document $document): array
    {
        $settings = AnswerModelSetting::current();
        if (! $settings->isConfigured()) {
            // No key → no proposal. The source simply stays unsegmented.
            return ['status' => 'skipped', 'reason' => 'answer_model_not_configured'];
        }

        $document->loadMissing('pages');
        $pagesText = $document->pages
            ->sortBy('page_number')
            ->map(fn ($p) => (string) $p->text)
            ->implode("\n\n");

        if (trim($pagesText) === '') {
            return ['status' => 'skipped', 'reason' => 'no_extractable_text'];
        }

        $format = str_ends_with(strtolower((string) $document->storage_path), '.xlsx') ? 'xlsx' : 'docx';

        $providerConfig = [
            'provider' => config('services.hr_ai.answer_provider', 'claude'),
            'model' => config('services.hr_ai.answer_model'),
            'endpoint' => config('services.hr_ai.answer_endpoint'),
        ];

        $key = $settings->decryptKey();
        $result = $this->ai->segmentFacts(
            $document->id,
            $document->uuid,
            $format,
            $pagesText,
            $this->buildCandidateConvenios(),
            $this->buildCandidateTopics(),
            $key,
            $providerConfig,
        );
        unset($key); // drop the plaintext as soon as the call returns

        if (isset($result['error'])) {
            Log::warning('reference segmentation: provider failure (source left unsegmented)', [
                'document_id' => $document->id,
                'error' => $result['error'], // never the key
            ]);

            return ['status' => 'error', 'reason' => $result['error']];
        }

        return $this->persist($document, $result['facts'] ?? []);
    }

    /**
     * Persist the segmented facts as inert `ai_agent`/`needs_review` rows,
     * upserting on the group_label-extended logical key + flagging obvious
     * version duplicates. Append-only provenance in `tag_events`.
     *
     * @param  list<array<string,mixed>>  $facts  hr-ai /segment-facts envelope facts
     * @return array<string,mixed>
     */
    private function persist(Document $document, array $facts): array
    {
        $batchId = (string) Str::uuid();
        // Validity rides the SOURCE document's human-set window (Q7) — the agent
        // never parses dates from prose. NULL = open-ended.
        $validityStart = $document->validity_start?->toDateString();
        $validityEnd = $document->validity_end?->toDateString();

        $created = 0;
        $updated = 0;
        $duplicatesFlagged = 0;

        DB::transaction(function () use ($document, $facts, $batchId, $validityStart, $validityEnd, &$created, &$updated, &$duplicatesFlagged) {
            foreach ($facts as $f) {
                $convenioId = $f['convenio_id'] ?? null;
                $value = trim((string) ($f['value'] ?? ''));
                if ($convenioId === null || $value === '') {
                    continue; // defensive — hr-ai already validated, but never write a scopeless/empty fact
                }

                $groupLabel = isset($f['group_label']) && trim((string) $f['group_label']) !== ''
                    ? trim((string) $f['group_label'])
                    : null;
                $topicId = $f['topic_id'] ?? null;
                $jobCategoryId = $f['job_category_id'] ?? null;

                $key = [
                    'source' => 'ai_agent',
                    'source_document_id' => $document->id,
                    'convenio_id' => $convenioId,
                    'topic_id' => $topicId,
                    'job_category_id' => $jobCategoryId,
                    'group_label' => $groupLabel,
                    'validity_start' => $validityStart,
                    'validity_end' => $validityEnd,
                ];

                $existing = $this->findByLogicalKey($key);
                $isNew = $existing === null;

                $fact = $existing ?? new ReferenceFact;
                $fact->fill([
                    ...$key,
                    'value' => $value,
                    'raw_values' => $f['raw_values'] ?? null,
                    'confidence' => isset($f['confidence']) ? (float) $f['confidence'] : null,
                    'uncertainty' => $this->normalizeUncertainty($f['uncertainty'] ?? null),
                    // INVARIANT 1: forced to the floor regardless of anything.
                    'authority_level' => ReferenceFact::AUTHORITY_LEVEL,
                    // inert until a human verifies (the agent never flips this).
                    'status' => 'needs_review',
                    'source_locator' => $f['source_locator'] ?? null,
                    'source_excerpt' => $f['source_excerpt'] ?? null,
                    'proposal_batch_id' => $batchId,
                ]);
                // Never resurrect a previously-rejected proposal silently.
                if (! $isNew && $existing->status === 'rejected') {
                    $fact->status = 'needs_review';
                }
                $fact->save();

                // Obvious-duplicate flag (Q4): a DIFFERENT existing fact with the
                // same SCOPE identity (convenio/topic/job_category/group) but a
                // DIFFERING value — a likely version/update. Signal only (7d).
                $dupe = $this->findScopeDuplicate($fact);
                if ($dupe !== null) {
                    $fact->duplicate_of_id = $dupe->id;
                    if ($fact->uncertainty === null) {
                        $fact->uncertainty = ['field' => 'version', 'reason' => "posible versión de #{$dupe->id} (mismo ámbito, valor distinto)"];
                    }
                    $fact->save();
                    $duplicatesFlagged++;
                    $this->logEvent($fact->id, 'duplicate', null, (string) $dupe->id, 'AI flagged possible version/duplicate (signal only — resolution is 7d)');
                }

                $this->logEvent(
                    $fact->id,
                    'reference_fact',
                    null,
                    'needs_review',
                    $isNew ? 'AI segmented (inert; awaiting human verify)' : 'AI re-segmented (upsert on logical key)',
                    $fact->confidence,
                );

                $isNew ? $created++ : $updated++;
            }
        });

        return [
            'status' => 'ok',
            'batch_id' => $batchId,
            'facts' => count($facts),
            'created' => $created,
            'updated' => $updated,
            'duplicates_flagged' => $duplicatesFlagged,
        ];
    }

    /**
     * Find an existing `ai_agent` fact for this source by the group_label-extended
     * logical key, null-safe on every nullable column (Eloquent `where(col,null)`
     * emits `col = null`, which never matches — so nulls use whereNull).
     *
     * @param  array<string,mixed>  $key
     */
    private function findByLogicalKey(array $key): ?ReferenceFact
    {
        $query = ReferenceFact::query();
        foreach ($key as $col => $val) {
            $val === null ? $query->whereNull($col) : $query->where($col, $val);
        }

        return $query->first();
    }

    /**
     * Find a DIFFERENT fact sharing this fact's SCOPE identity (convenio, topic,
     * job_category, normalized group_label) but carrying a DIFFERING value — the
     * obvious version/update signal (Q4). Exact-key-plus-different-value only; no
     * semantic comparison (that is 7d). Rejected facts are ignored.
     */
    private function findScopeDuplicate(ReferenceFact $fact): ?ReferenceFact
    {
        $candidates = ReferenceFact::query()
            ->where('id', '!=', $fact->id)
            ->where('status', '!=', 'rejected')
            ->where('convenio_id', $fact->convenio_id)
            ->when($fact->topic_id === null, fn ($q) => $q->whereNull('topic_id'), fn ($q) => $q->where('topic_id', $fact->topic_id))
            ->when($fact->job_category_id === null, fn ($q) => $q->whereNull('job_category_id'), fn ($q) => $q->where('job_category_id', $fact->job_category_id))
            ->orderByDesc('id')
            ->get();

        $thisGroup = $this->normalizeGroup($fact->group_label);
        foreach ($candidates as $c) {
            if ($this->normalizeGroup($c->group_label) !== $thisGroup) {
                continue; // a different group is a different fact, not a duplicate
            }
            if (trim((string) $c->value) !== trim((string) $fact->value)) {
                return $c; // same scope, different value → a likely version
            }
        }

        return null;
    }

    /** trim/collapse/case-fold for group matching (stored as-seen, matched normalized). */
    private function normalizeGroup(?string $s): string
    {
        if ($s === null) {
            return '';
        }

        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $s)));
    }

    /**
     * @param  mixed  $u
     * @return array<string,string>|null
     */
    private function normalizeUncertainty($u): ?array
    {
        if (! is_array($u)) {
            return null;
        }
        $field = trim((string) ($u['field'] ?? ''));
        $reason = trim((string) ($u['reason'] ?? ''));
        if ($field === '' && $reason === '') {
            return null;
        }

        return ['field' => $field !== '' ? $field : 'scope', 'reason' => $reason];
    }

    private function logEvent(int $factId, string $facet, ?string $old, ?string $new, string $note, ?float $confidence = null): void
    {
        TagEvent::create([
            'entity_type' => 'reference_fact',
            'entity_id' => $factId,
            'facet' => $facet,
            'old_value' => $old,
            'new_value' => $new,
            'source' => 'ai_agent', // the lane lights here for facts (7b-2)
            'actor_id' => null,
            'confidence' => $confidence,
            'note' => $note,
        ]);
    }

    /**
     * The CLOSED candidate convenios, convenio-centric (Q2): each carries its
     * DERIVED territory + sector (+ sparse job categories) so the model binds to
     * a real id whose (territory, sector) matches the carried header. The corpus
     * is small (~28 convenios) so the full set is passed — the agent needs all of
     * them to bind cross-province (Álava vs Andalucía vs Estatal COEAS).
     *
     * @return list<array<string,mixed>>
     */
    private function buildCandidateConvenios(): array
    {
        return Convenio::with(['territory', 'sector', 'jobCategories'])
            ->orderBy('id')->get()
            ->map(fn (Convenio $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'numero' => $c->numero,
                'aliases' => array_values((array) $c->aliases),
                'territory_name' => $c->territory?->name ?? '',
                'territory_aliases' => array_values((array) ($c->territory?->aliases ?? [])),
                'sector_name' => $c->sector?->name ?? '',
                'sector_aliases' => array_values((array) ($c->sector?->aliases ?? [])),
                'job_categories' => $c->jobCategories
                    ->map(fn ($jc) => ['id' => $jc->id, 'name' => $jc->name, 'group_code' => $jc->group_code])
                    ->values()->all(),
            ])->values()->all();
    }

    /**
     * Approved topics (the agent binds, never mints — ADR-0011). The fixtures are
     * all `periodo de prueba` (seeded approved, Q6); the full small list is passed.
     *
     * @return list<array<string,mixed>>
     */
    private function buildCandidateTopics(): array
    {
        return Topic::where('status', 'approved')->orderBy('name')->get()
            ->map(fn ($t) => ['id' => $t->id, 'name' => $t->name])
            ->values()->all();
    }
}
