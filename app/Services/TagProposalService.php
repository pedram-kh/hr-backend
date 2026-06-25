<?php

namespace App\Services;

use App\Models\AnswerModelSetting;
use App\Models\Convenio;
use App\Models\Document;
use App\Models\DocumentTopic;
use App\Models\DocumentType;
use App\Models\Sector;
use App\Models\TagEvent;
use App\Models\Territory;
use App\Models\Topic;
use App\Support\VocabularyResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The LLM tagging tier — persist side (Sprint 7a, ADR-0011/0020).
 *
 * hr-ai READS a document's page text and PROPOSES document-level facets; THIS
 * service is the only writer. It enforces the two non-negotiable safety
 * invariants:
 *
 *  INVARIANT 1 — the AI keeps the document `under_review`. It NEVER writes
 *    `auto_proposed`/`verified` (those make a doc retrievable). It touches
 *    `tagging_status` not at all; only the human verify action (Sprint-3
 *    DocumentController::confirm) flips under_review → verified. So the
 *    embedding gate (`tagging_status != under_review`) keeps an AI-proposed doc
 *    unretrievable (0 chunks) until a human verifies.
 *
 *  INVARIANT 2 — the AI writes ONLY `ai_agent` provenance. It records
 *    `ai_agent` tag_events + unverified `document_topics` + `tagging_confidence`.
 *    It NEVER writes the authoritative scope FKs (convenio_id / document_type_id
 *    / validity_* / retrieval_status). Those change only by the human
 *    verify/reassign action. No AI write can alter scope or eligibility.
 *
 * It binds into existing vocabulary only (hr-ai already validated the candidate
 * ids); unresolvable values are recorded as `raw_unmatched_values` (with the
 * AI's variant hint) for the propose-new-vocabulary flow — it never invents a
 * vocabulary value. Document-level facet tagging only (never 7b segmentation).
 */
class TagProposalService
{
    /** Convenio shortlist cap handed to the model (bounded prompt). */
    private const CONVENIO_SHORTLIST_CAP = 60;

    public function __construct(private ExtractionClient $ai) {}

    /**
     * Propose facets for one document and persist them as inert AI provenance.
     *
     * @return array<string,mixed> a summary for the caller (job/endpoint)
     */
    public function propose(Document $document): array
    {
        $settings = AnswerModelSetting::current();
        if (! $settings->isConfigured()) {
            // No key → no proposal. The doc simply stays in the human queue.
            return ['status' => 'skipped', 'reason' => 'answer_model_not_configured'];
        }

        $document->loadMissing('pages');
        $pageText = $document->pages
            ->sortBy('page_number')
            ->map(fn ($p) => (string) $p->text)
            ->implode("\n\n");

        if (trim($pageText) === '') {
            // A scan with no extractable text (OCR is out of scope this sprint).
            return ['status' => 'skipped', 'reason' => 'no_extractable_text'];
        }

        $providerConfig = [
            'provider' => config('services.hr_ai.answer_provider', 'claude'),
            'model' => config('services.hr_ai.answer_model'),
            'endpoint' => config('services.hr_ai.answer_endpoint'),
        ];

        $key = $settings->decryptKey();
        $result = $this->ai->proposeTags(
            $document->id,
            $pageText,
            $this->buildCandidateVocabulary($document),
            $key,
            $providerConfig,
        );
        unset($key); // drop the plaintext as soon as the call returns

        if (isset($result['error'])) {
            Log::warning('tag proposal: provider failure (doc left in human queue)', [
                'document_id' => $document->id,
                'error' => $result['error'], // never the key
            ]);

            return ['status' => 'error', 'reason' => $result['error']];
        }

        return $this->persist($document, $result);
    }

    /**
     * Persist the AI proposal as inert `ai_agent` provenance. Enforces both
     * invariants: tagging_status is never touched; the FK columns are never
     * written. Append-only (tag_events / document_topics).
     *
     * @param  array<string,mixed>  $result  hr-ai /propose-tags envelope
     * @return array<string,mixed>
     */
    private function persist(Document $document, array $result): array
    {
        $facets = $result['facets'] ?? [];
        $topics = $result['topics'] ?? [];
        $rawUnmatched = $result['raw_unmatched_values'] ?? [];
        $overall = $result['overall_confidence'] ?? null;

        // INVARIANT 2 (belt-and-braces): snapshot the authoritative FK columns so
        // a regression that tried to write them would be caught by the test that
        // compares against this snapshot. We deliberately never assign them below.
        $beforeStatus = $document->tagging_status;

        DB::transaction(function () use ($document, $facets, $topics, $rawUnmatched, $overall) {
            // (a) Each proposed facet → one append-only `ai_agent` tag_events row.
            //     This is a SUGGESTION surfaced in the review UI — NOT a write of
            //     the document's FK columns (invariant 2).
            foreach ($facets as $f) {
                $display = $this->facetDisplay($f);
                if ($display === null) {
                    continue;
                }
                TagEvent::create([
                    'entity_type' => 'document',
                    'entity_id' => $document->id,
                    'facet' => $f['facet'],
                    'old_value' => null,
                    'new_value' => $display,
                    'source' => 'ai_agent',
                    'actor_id' => null,
                    'confidence' => $f['confidence'] ?? null,
                    'note' => 'AI proposal (inert; awaiting human verify)',
                ]);
            }

            // (b) Proposed topics → UNVERIFIED `ai_agent` document_topics
            //     (verified_by/verified_at null). The human verify confirms them.
            foreach ($topics as $t) {
                $topic = Topic::where('id', $t['topic_id'] ?? 0)->where('status', 'approved')->first();
                if ($topic === null) {
                    continue;
                }
                if ($document->topics()->where('topics.id', $topic->id)->exists()) {
                    continue; // already applied (don't duplicate)
                }
                DocumentTopic::create([
                    'document_id' => $document->id,
                    'topic_id' => $topic->id,
                    'source' => 'ai_agent',
                    'confidence' => $t['confidence'] ?? null,
                    'verified_by' => null,
                    'verified_at' => null,
                ]);
                TagEvent::create([
                    'entity_type' => 'document',
                    'entity_id' => $document->id,
                    'facet' => 'topic',
                    'old_value' => null,
                    'new_value' => $topic->name,
                    'source' => 'ai_agent',
                    'actor_id' => null,
                    'confidence' => $t['confidence'] ?? null,
                    'note' => 'AI proposed topic (unverified)',
                ]);
            }

            // (c) Unresolvable values → merge the AI's variant hint into the open
            //     review task's raw_unmatched_values (feeds the propose-vocabulary
            //     flow) + an `ai_agent` provenance note. The AI never invents.
            $task = $document->reviewTasks()
                ->where('status', 'open')
                ->orderByRaw("CASE WHEN reason = 'unresolved' THEN 0 ELSE 1 END")
                ->first();
            if ($task !== null && $rawUnmatched !== []) {
                $task->raw_unmatched_values = $this->mergeRawUnmatched(
                    (array) ($task->raw_unmatched_values ?? []),
                    $rawUnmatched,
                );
                $task->save();
            }
            foreach ($rawUnmatched as $rv) {
                TagEvent::create([
                    'entity_type' => 'document',
                    'entity_id' => $document->id,
                    'facet' => $rv['facet'] ?? 'unknown',
                    'old_value' => null,
                    'new_value' => null,
                    'source' => 'ai_agent',
                    'actor_id' => null,
                    'confidence' => null,
                    'note' => 'AI unresolved value: '.($rv['value'] ?? '').(isset($rv['variant_of']['id']) ? " (looks like a variant of #{$rv['variant_of']['id']})" : ''),
                ]);
            }

            // (d) tagging_confidence drives the review queue order (data-model §5).
            //     INVARIANT 1: tagging_status is NOT touched here — the doc stays
            //     under_review (the embedding gate holds) until a human verifies.
            if ($overall !== null) {
                $document->tagging_confidence = (float) $overall;
                $document->save(); // only tagging_confidence is dirty
            }
        });

        return [
            'status' => 'ok',
            'facets' => count($facets),
            'topics' => count($topics),
            'raw_unmatched' => count($rawUnmatched),
            'tagging_status' => $beforeStatus, // unchanged (invariant 1)
            'overall_confidence' => $overall,
        ];
    }

    /**
     * The human-readable value for an `ai_agent` tag_events `new_value`, matching
     * the filename_parse conventions (numero / code / name / "start..end").
     *
     * @param  array<string,mixed>  $facet
     */
    private function facetDisplay(array $facet): ?string
    {
        return match ($facet['facet'] ?? null) {
            'document_type' => isset($facet['value_code']) ? (string) $facet['value_code'] : null,
            'convenio' => optional(Convenio::find($facet['value_id'] ?? 0))->numero,
            'territory' => ($t = Territory::find($facet['value_id'] ?? 0)) ? (string) ($t->code ?? $t->name) : null,
            'sector' => optional(Sector::find($facet['value_id'] ?? 0))->name,
            'validity' => isset($facet['value']) && trim((string) $facet['value']) !== '' ? (string) $facet['value'] : null,
            default => null,
        };
    }

    /**
     * Build the CLOSED candidate vocabulary for the proposal prompt (§7.1):
     * the full territory/sector/document_type lists (tiny, closed) + the approved
     * topics + a convenio shortlist (by parser hint). The model binds to these
     * ids/codes only; it never invents a value.
     *
     * @return array<string,mixed>
     */
    private function buildCandidateVocabulary(Document $document): array
    {
        return [
            'document_types' => DocumentType::orderBy('id')->get()
                ->map(fn ($d) => ['id' => $d->id, 'name' => $d->name, 'code' => $d->code])->values()->all(),
            'territories' => Territory::orderBy('id')->get()
                ->map(fn ($t) => ['id' => $t->id, 'name' => $t->name, 'aliases' => array_values((array) $t->aliases), 'code' => $t->code])->values()->all(),
            'sectors' => Sector::orderBy('id')->get()
                ->map(fn ($s) => ['id' => $s->id, 'name' => $s->name, 'aliases' => array_values((array) $s->aliases)])->values()->all(),
            'topics' => Topic::where('status', 'approved')->orderBy('name')->get()
                ->map(fn ($t) => ['id' => $t->id, 'name' => $t->name])->values()->all(),
            'convenios' => $this->convenioShortlist($document),
        ];
    }

    /**
     * Convenio shortlist by parser hint: convenios whose territory matches a
     * territory hint resolved from the open review task's raw_unmatched_values or
     * the document's filename/title. If no hint resolves and the corpus is small
     * enough, the full (bounded) list is passed so the model can still bind.
     *
     * @return list<array<string,mixed>>
     */
    private function convenioShortlist(Document $document): array
    {
        $vocab = new VocabularyResolver;
        $territoryIds = [];

        $hints = [];
        $task = $document->reviewTasks()->where('status', 'open')->first();
        foreach ((array) ($task?->raw_unmatched_values ?? []) as $rv) {
            if (($rv['facet'] ?? null) === 'territory' && ! empty($rv['value'])) {
                $hints[] = (string) $rv['value'];
            }
        }
        $hints[] = (string) $document->source_filename;
        $hints[] = (string) $document->title;

        foreach ($hints as $h) {
            $t = $vocab->territoryByName($h);
            if ($t !== null) {
                $territoryIds[$t->id] = true;
            }
        }

        $query = Convenio::query()->orderBy('id');
        if ($territoryIds !== []) {
            $query->whereIn('territory_id', array_keys($territoryIds));
        }
        $shortlist = $query->limit(self::CONVENIO_SHORTLIST_CAP)->get();

        // No hint resolved → pass the bounded full list when the corpus is small.
        if ($shortlist->isEmpty() && Convenio::count() <= self::CONVENIO_SHORTLIST_CAP) {
            $shortlist = Convenio::orderBy('id')->limit(self::CONVENIO_SHORTLIST_CAP)->get();
        }

        return $shortlist
            ->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'aliases' => array_values((array) $c->aliases), 'code' => $c->numero])
            ->values()->all();
    }

    /**
     * Merge the AI's raw_unmatched_values (which may carry a `variant_of` hint)
     * into the existing parser-recorded list, keyed by (facet,value): an existing
     * entry gains the AI's variant hint; a genuinely new AI entry is appended.
     *
     * @param  array<int,array<string,mixed>>  $existing
     * @param  array<int,array<string,mixed>>  $aiValues
     * @return array<int,array<string,mixed>>
     */
    private function mergeRawUnmatched(array $existing, array $aiValues): array
    {
        $keyOf = fn ($e) => strtolower(trim((string) ($e['facet'] ?? '')).'|'.trim((string) ($e['value'] ?? '')));
        $index = [];
        foreach ($existing as $i => $e) {
            $index[$keyOf($e)] = $i;
        }
        foreach ($aiValues as $ai) {
            $k = $keyOf($ai);
            if (isset($index[$k])) {
                if (isset($ai['variant_of'])) {
                    $existing[$index[$k]]['variant_of'] = $ai['variant_of'];
                }
                $existing[$index[$k]]['ai_flagged'] = true;
            } else {
                $entry = ['facet' => $ai['facet'] ?? 'unknown', 'value' => $ai['value'] ?? '', 'ai_flagged' => true];
                if (isset($ai['variant_of'])) {
                    $entry['variant_of'] = $ai['variant_of'];
                }
                $existing[] = $entry;
                $index[$k] = array_key_last($existing);
            }
        }

        return array_values($existing);
    }
}
