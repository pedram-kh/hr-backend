<?php

namespace App\Services;

use App\Models\AnswerModelSetting;
use App\Models\Convenio;
use App\Models\ConvenioGroup;
use App\Models\Document;
use App\Models\ReferenceFact;
use App\Models\TagEvent;
use App\Models\Topic;
use App\Support\TopicLexicon;
use Illuminate\Support\Collection;
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
     * Sprint 10c (spec §2.4): `$capturedValidityStart`/`$capturedValidityEnd` are
     * REQUIRED (nullable typed, no default) — deliberately, so a caller cannot
     * silently omit them and fall back to some implicit re-read. They must be
     * the document's validity window as read by the CALLER at dispatch time
     * (see `SegmentReferenceSource`'s docblock), never re-fetched from
     * `$document` here — `$document` may be a stale in-memory copy or, worse, a
     * fresh reload reflecting an edit made after this job was queued. This is
     * the job-time-vs-dispatch-time fix: nothing downstream of dispatch ever
     * reads validity off the document again.
     *
     * @return array<string,mixed> a summary for the caller (job/endpoint)
     */
    public function propose(Document $document, ?string $capturedValidityStart, ?string $capturedValidityEnd): array
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

        // Sprint 10c (D1): log token counts from hr-ai's trace_fragment so batch
        // cost is a MEASUREMENT, not an estimate — one line, no new storage, no
        // new endpoint. Never fails the call: trace_fragment is best-effort and
        // absent entirely on a parse-salvage path (claude.py's own fallback).
        $trace = $result['trace_fragment'] ?? [];
        Log::info('reference segmentation: usage', [
            'document_id' => $document->id,
            'model' => $trace['model'] ?? null,
            'prompt_tokens' => $trace['prompt_tokens'] ?? null,
            'completion_tokens' => $trace['completion_tokens'] ?? null,
            'fact_count' => $trace['fact_count'] ?? count($result['facts'] ?? []),
            'truncated' => $trace['truncated'] ?? null,
            // Sprint 10c jornada eval finding: `truncated` above is OUTPUT-only
            // (the model's own response hitting max_tokens) — this is the
            // INPUT-side signal (hr-ai's `text_truncated`, added the same
            // sprint after the jornada eval found it silently absent), so a
            // huge reference_source is never truncated without a trace.
            'text_truncated' => $trace['text_truncated'] ?? null,
            'salvaged' => $trace['salvaged'] ?? null,
        ]);

        return $this->persist($document, $result['facts'] ?? [], $capturedValidityStart, $capturedValidityEnd);
    }

    /**
     * Sprint 10c (plan §A.1, §D.11 step 3) — the per-(document, topic) driver
     * over ALREADY-INGESTED convenio text, alongside (not replacing) the
     * `reference_source` path above. Two structural differences from
     * `propose()`:
     *
     *  1. SOURCE: convenio text already in `document_pages`, not an uploaded
     *     recopilación — `$document` here is a `convenio_text` document.
     *  2. SCOPE: single-convenio (this document's own `convenio_id`) and
     *     single-topic (`$topic`, passed to hr-ai as `target_topic` — NOT a
     *     closed list to bind against opportunistically). No header-carry:
     *     a single-convenio call has no cross-province ambiguity to resolve.
     *
     * Input is PASSAGE-SCOPED (plan §A.1's sizing finding): only pages
     * carrying a `TopicLexicon` anchor for `$topic`, not the whole convenio
     * (real convenio text runs up to 263k chars against a 48k-char cap).
     *
     * Source selection is the CALLER's job (see `eligibleDocumentsForTopic()`)
     * — by the time a `$document` reaches here it has already passed the
     * `document_type=convenio_text` + `retrieval_status='active'` filter
     * (D6, plan §B.6): an expired convenio (4/21) is excluded upstream
     * because it has no active row, never via a hardcoded skip list here.
     *
     * `$capturedValidityStart`/`$capturedValidityEnd` follow the exact same
     * dispatch-time-capture discipline as `propose()` (spec §2.4) — required
     * (nullable-typed, no default), never re-read from `$document` here.
     *
     * @return array<string,mixed>
     */
    public function proposeForTopic(Document $document, Topic $topic, ?string $capturedValidityStart, ?string $capturedValidityEnd): array
    {
        $settings = AnswerModelSetting::current();
        if (! $settings->isConfigured()) {
            return ['status' => 'skipped', 'reason' => 'answer_model_not_configured'];
        }

        $document->loadMissing(['convenio.territory', 'convenio.sector', 'convenio.jobCategories']);
        $convenio = $document->convenio;
        if ($convenio === null) {
            return ['status' => 'skipped', 'reason' => 'no_convenio'];
        }

        $topicKey = TopicLexicon::keyForTopicName($topic->name);
        if ($topicKey === null) {
            return ['status' => 'skipped', 'reason' => 'topic_not_in_lexicon'];
        }

        $pagesText = $this->passageScopedText($document, $topicKey);
        if (trim($pagesText) === '') {
            return ['status' => 'skipped', 'reason' => 'no_anchored_passages'];
        }

        $providerConfig = [
            'provider' => config('services.hr_ai.answer_provider', 'claude'),
            'model' => config('services.hr_ai.answer_model'),
            'endpoint' => config('services.hr_ai.answer_endpoint'),
        ];

        $key = $settings->decryptKey();
        $result = $this->ai->segmentFacts(
            $document->id,
            $document->uuid,
            'docx', // convenio text is always prose, never a salary grid
            $pagesText,
            [$this->convenioPayload($convenio)], // single-convenio: no cross-province binding needed
            [], // candidate_topics unused when target_topic is set
            $key,
            $providerConfig,
            ['id' => $topic->id, 'name' => $topic->name],
        );
        unset($key); // drop the plaintext as soon as the call returns

        if (isset($result['error'])) {
            Log::warning('topic segmentation: provider failure (convenio left unsegmented for this topic)', [
                'document_id' => $document->id,
                'convenio_id' => $convenio->id,
                'topic_id' => $topic->id,
                'error' => $result['error'], // never the key
            ]);

            return ['status' => 'error', 'reason' => $result['error']];
        }

        // D1: same measurement-not-estimate logging as propose(), plus the
        // topic/convenio identity so per-topic batch cost is attributable.
        $trace = $result['trace_fragment'] ?? [];
        Log::info('topic segmentation: usage', [
            'document_id' => $document->id,
            'convenio_id' => $convenio->id,
            'topic_id' => $topic->id,
            'topic_name' => $topic->name,
            'model' => $trace['model'] ?? null,
            'prompt_tokens' => $trace['prompt_tokens'] ?? null,
            'completion_tokens' => $trace['completion_tokens'] ?? null,
            'fact_count' => $trace['fact_count'] ?? count($result['facts'] ?? []),
            'truncated' => $trace['truncated'] ?? null,
            // Sprint 10c jornada eval finding (this IS the path it was found
            // on): `truncated` above is OUTPUT-only; this is hr-ai's new
            // INPUT-side signal (TOPIC_SEGMENT_TEXT_CAP) — a broad anchor
            // (e.g. 'jornada') can produce a passage-scoped set exceeding the
            // cap, previously silently cutting off real content (convenio 25,
            // page 45) with zero trace. Now measured, never silent.
            'text_truncated' => $trace['text_truncated'] ?? null,
            'salvaged' => $trace['salvaged'] ?? null,
        ]);

        return $this->persist($document, $result['facts'] ?? [], $capturedValidityStart, $capturedValidityEnd);
    }

    /**
     * The eligible `(document)` set for a topic's batch (D6, plan §B.6):
     * `document_type=convenio_text` AND `retrieval_status='active'`, full
     * stop, further narrowed to documents carrying at least one page anchored
     * to this topic (so a batch driver never dispatches a job with nothing to
     * do). No convenio_id skip list, ever — an expired convenio (4/21) is
     * excluded here ONLY because it has no `active` row, proven directly by
     * `Sprint10cTopicSegmentationTest`'s source-selection test.
     *
     * @return Collection<int,Document>
     */
    public function eligibleDocumentsForTopic(Topic $topic): Collection
    {
        $topicKey = TopicLexicon::keyForTopicName($topic->name);
        if ($topicKey === null) {
            return collect();
        }

        return Document::query()
            ->whereHas('documentType', fn ($q) => $q->where('code', 'convenio_text'))
            ->where('retrieval_status', 'active')
            ->with('pages')
            ->get()
            ->filter(fn (Document $d) => trim($this->passageScopedText($d, $topicKey)) !== '')
            ->values();
    }

    /**
     * Only this document's pages carrying a `TopicLexicon` anchor for
     * `$topicKey` (plan §A.1's sizing finding — passage-scoped, not the whole
     * convenio). Each matching page is prefixed with a `[loc:pN]` marker so
     * the model's `source_locator` (rule 9) can cite a real, checkable page.
     */
    private function passageScopedText(Document $document, string $topicKey): string
    {
        $document->loadMissing('pages');

        return $document->pages
            ->sortBy('page_number')
            ->filter(fn ($p) => TopicLexicon::textMatchesTopicKey($topicKey, (string) $p->text))
            ->map(fn ($p) => "[loc:p{$p->page_number}]\n".(string) $p->text)
            ->values()
            ->implode("\n\n");
    }

    /**
     * Persist the segmented facts as inert `ai_agent`/`needs_review` rows,
     * upserting on the group_label-extended logical key + flagging obvious
     * version duplicates. Append-only provenance in `tag_events`.
     *
     * @param  list<array<string,mixed>>  $facts  hr-ai /segment-facts envelope facts
     * @return array<string,mixed>
     */
    private function persist(Document $document, array $facts, ?string $validityStart, ?string $validityEnd): array
    {
        $batchId = (string) Str::uuid();
        // Validity rides the CAPTURED-AT-DISPATCH window (Sprint 10c, spec §2.4)
        // — never re-read from `$document` here. The agent never parses dates
        // from prose (unchanged, Q7); what changed is WHEN the human-set window
        // is read: at dispatch, by the caller, not at job-execution time here.
        // NULL = open-ended.

        $created = 0;
        $updated = 0;
        $duplicatesFlagged = 0;

        // Sprint 10c (D4) — the group-restraint DETERMINISTIC backstop. The
        // segmentation prompt has no rule at all for "this convenio has no
        // approved group tree" (plan §A.3: the agent proposes a group-labelled
        // fact unconditionally, regardless of tree status). This is enforced
        // here, in code, not the prompt: computed ONCE per batch, the set of
        // convenio_ids among this batch's facts that already have an APPROVED
        // `convenio_groups` tree. Any OTHER convenio_id with a non-null
        // group_label gets its uncertainty forced below — never a skip
        // (flag-and-persist, D4): the fact is still real, reviewable
        // information; a human may verify it AND separately approve/bind a
        // tree later (7f's manual-bind lane exists for exactly this).
        $approvedTreeConvenioIds = ConvenioGroup::approved()
            ->whereIn('convenio_id', collect($facts)->pluck('convenio_id')->filter()->unique()->values()->all())
            ->pluck('convenio_id')
            ->unique()
            ->all();

        DB::transaction(function () use ($document, $facts, $batchId, $validityStart, $validityEnd, $approvedTreeConvenioIds, &$created, &$updated, &$duplicatesFlagged) {
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

                // D4 backstop: a group-labelled fact for a convenio with no
                // approved tree gets flagged (never overriding an uncertainty
                // the model already set — the model's own flag, e.g. a compound
                // group expression, is a real and different concern).
                $uncertainty = $this->normalizeUncertainty($f['uncertainty'] ?? null);
                if ($uncertainty === null && $groupLabel !== null && ! in_array($convenioId, $approvedTreeConvenioIds, true)) {
                    $uncertainty = [
                        'field' => 'group',
                        'reason' => 'no hay árbol de grupos aprobado para este convenio — revisar y vincular manualmente',
                    ];
                }

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
                    'uncertainty' => $uncertainty,
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
            ->map(fn (Convenio $c) => $this->convenioPayload($c))
            ->values()->all();
    }

    /**
     * The single-convenio payload shape shared by `buildCandidateConvenios()`
     * (all 27, for the multi-province `propose()` path) and `proposeForTopic()`
     * (exactly 1, for the single-convenio Sprint 10c path) — one implementation
     * of the candidate-convenio shape hr-ai's closed-set binding expects.
     *
     * @return array<string,mixed>
     */
    private function convenioPayload(Convenio $c): array
    {
        return [
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
        ];
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
