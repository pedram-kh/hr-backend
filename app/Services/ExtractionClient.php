<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Calls hr-ai's internal endpoints (ADR-0010/0013):
 *  - /extract        PDF → per-page text + page-image keys (Sprint 1).
 *  - /extract-salary salary .xlsx → structured rows (extract-and-return; hr-ai
 *                    writes NO salary rows — hr-backend does).
 *  - /embed          re-extract column-aware → de-space → chunk → embed → hr-ai
 *                    WRITES document_chunks directly; hr-backend passes the
 *                    resolved scope (ADR-0007).
 *  - /synthesise     Sprint 2b-1 (ADR-0015): compose a CITED answer grounded only
 *                    in the eligible chunks. hr-backend passes the DECRYPTED key
 *                    in the body (never a header) per call; hr-ai never persists
 *                    it. hr-backend (not hr-ai) owns the answer-or-escalate
 *                    decision.
 *  - /compare-scope  Sprint 7d (ADR-0024): read-only semantic comparison — embed
 *                    N probe texts, rank a scope's chunks against each with the
 *                    authority band filtered IN THE SQL. No LLM, no write.
 *  - /embed-batch    Sprint 8 Step 5 (plan.md §1.3, ADR-0030): read-only,
 *                    string[] -> vector[] (BGE-M3), no DB touch at all. Used
 *                    ONLY by the nightly question-cluster job — never by the
 *                    employee answer loop.
 * hr-backend (this app) remains the only writer of every table except
 * document_chunks.
 */
class ExtractionClient
{
    private function base(): string
    {
        return rtrim((string) config('services.hr_ai.url'), '/');
    }

    private function token(): string
    {
        return (string) config('services.hr_ai.internal_token');
    }

    /**
     * @return array{page_count:int, pages:list<array{page_number:int,text:string,image_key:string,extraction_source:string}>}
     */
    public function extract(string $storageKey, string $documentUuid, bool $ocr = false, int $ocrPageCap = 60): array
    {
        $response = Http::withHeaders(['X-Internal-Token' => $this->token()])
            ->timeout(180)
            ->acceptJson()
            ->post("{$this->base()}/extract", [
                'storage_key' => $storageKey,
                'document_uuid' => $documentUuid,
                // Sprint 7e (ADR-0026, review.md §2.1): additive, default off. hr-ai
                // NEVER calls the OCR model here — it only marks a text-less page
                // (within the cap) as extraction_source=ocr_pending; this call's
                // latency is unchanged either way (still the sub-second PyMuPDF path).
                'ocr' => $ocr,
                'ocr_page_cap' => $ocrPageCap,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException("hr-ai /extract failed ({$response->status()}): ".$response->body());
        }

        return $response->json();
    }

    /**
     * Parse a salary .xlsx → { tables:[{sheet,year,validity_start,validity_end,
     * rows:[...]}], warnings:[...] }. hr-ai returns rows only; this app writes them.
     *
     * @return array<string,mixed>
     */
    public function extractSalary(string $storageKey, string $documentUuid): array
    {
        $response = Http::withHeaders(['X-Internal-Token' => $this->token()])
            ->timeout(180)
            ->acceptJson()
            ->post("{$this->base()}/extract-salary", [
                'storage_key' => $storageKey,
                'document_uuid' => $documentUuid,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException("hr-ai /extract-salary failed ({$response->status()}): ".$response->body());
        }

        return $response->json();
    }

    /**
     * Read a NON-salary .docx/.xlsx → structured per-section/per-sheet content
     * (Sprint 7b-1, ADR-0021). hr-ai READS and RETURNS only — it writes no DB
     * rows and never migrates (ADR-0007). hr-backend persists the content as
     * display `document_pages` (never `document_chunks` — queried-not-embedded,
     * ADR-0006). A content-extraction utility: it does NOT segment or assign
     * scope (that is 7b-2). A salary .xlsx is never sent here — it is routed to
     * extractSalary() by its document_type tag (Invariant 2).
     *
     * @param  'docx'|'xlsx'  $format
     * @return array{format:string, pages:list<array{page_number:int,label:string,text:string,locator:string}>}
     */
    public function readStructured(string $storageKey, string $documentUuid, string $format): array
    {
        $response = Http::withHeaders(['X-Internal-Token' => $this->token()])
            ->timeout(180)
            ->acceptJson()
            ->post("{$this->base()}/read-structured", [
                'storage_key' => $storageKey,
                'document_uuid' => $documentUuid,
                'format' => $format,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException("hr-ai /read-structured failed ({$response->status()}): ".$response->body());
        }

        return $response->json();
    }

    /**
     * Trigger chunk+embed for one document. hr-ai writes document_chunks directly
     * with the denormalized scope columns from $scope (resolved here, ADR-0007).
     *
     * @param  array<string,mixed>  $scope
     * @return array<string,mixed>
     */
    public function embed(int $documentId, string $documentUuid, string $storageKey, array $scope): array
    {
        $response = Http::withHeaders(['X-Internal-Token' => $this->token()])
            ->timeout(900) // model load + CPU embedding is a background admin path
            ->acceptJson()
            ->post("{$this->base()}/embed", [
                'document_id' => $documentId,
                'document_uuid' => $documentUuid,
                'storage_key' => $storageKey,
                'scope' => $scope,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException("hr-ai /embed failed ({$response->status()}): ".$response->body());
        }

        return $response->json();
    }

    /**
     * Vector retrieval primitive: scope-prefilter then exact similarity ranking
     * over document_chunks (data-model §11). Full recall (catch 2).
     *
     * @param  array<string,mixed>  $params
     * @return array<string,mixed>
     */
    public function retrieve(array $params): array
    {
        $response = Http::withHeaders(['X-Internal-Token' => $this->token()])
            ->timeout(180)
            ->acceptJson()
            ->post("{$this->base()}/retrieve", $params);

        if (! $response->successful()) {
            throw new RuntimeException("hr-ai /retrieve failed ({$response->status()}): ".$response->body());
        }

        return $response->json();
    }

    /**
     * Sandbox retrieval over ONE document's chunks (Sprint 3 Knowledge Center).
     * Read-only and additive — distinct from {@see retrieve()} so the employee
     * answer-loop primitive is untouched.
     *
     * @return array<string,mixed> { chunks: [...] }
     */
    public function sandboxRetrieve(string $query, int $documentId, int $k = 8): array
    {
        $response = Http::withHeaders(['X-Internal-Token' => $this->token()])
            ->timeout(180)
            ->acceptJson()
            ->post("{$this->base()}/sandbox-retrieve", [
                'query' => $query,
                'document_id' => $documentId,
                'k' => $k,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException("hr-ai /sandbox-retrieve failed ({$response->status()}): ".$response->body());
        }

        return $response->json();
    }

    /**
     * The Sprint-7d semantic COMPARISON primitive (ADR-0024). Read-only: hr-ai
     * embeds the probe texts and ranks the scope's chunks against each, with the
     * `authority_levels` band applied IN THE SQL — so a threshold decision on
     * `max_score` is k-independent (a post-top-k authority filter could hide the
     * one overlapping passage, a fail-open in a safety gate).
     *
     * THROWS on any transport/hr-ai failure. That is deliberate and load-bearing:
     * the caller (SemanticFenceService) must never be able to read a failure as
     * "no conflict" — it converts the exception into the fail-toward-caution
     * acknowledge band, never a clean publish.
     *
     * @param  array<string,mixed>  $params
     * @return array{matches:list<array<string,mixed>>, max_score:?float, eligible_total:int, probe_count:int}
     */
    public function compareScope(array $params): array
    {
        $response = Http::withHeaders(['X-Internal-Token' => $this->token()])
            ->timeout(180) // CPU embedding of N probes is a background admin path
            ->acceptJson()
            ->post("{$this->base()}/compare-scope", $params);

        if (! $response->successful()) {
            throw new RuntimeException("hr-ai /compare-scope failed ({$response->status()}): ".$response->body());
        }

        return $response->json();
    }

    /**
     * Synthesise a cited answer from the eligible chunks (ADR-0015). The
     * decrypted answer-model key is passed in the BODY (never a header) and used
     * by hr-ai for this one call only — never persisted there.
     *
     * Returns either the synthesis envelope
     *   { answer, citations, grounding_signal, confidence, authority_used, trace_fragment }
     * or, on a provider failure, { error: 'provider_error', detail }. hr-backend
     * treats the latter as an escalation (low_confidence) — it never throws on a
     * provider failure, so the employee always gets an honest escalation.
     *
     * @param  list<array<string,mixed>>  $chunks
     * @param  array{provider:string,model:string,endpoint:?string}  $providerConfig
     * @return array<string,mixed>
     */
    public function synthesise(string $question, array $chunks, string $decryptedKey, array $providerConfig): array
    {
        $response = Http::withHeaders(['X-Internal-Token' => $this->token()])
            ->timeout(120)
            ->acceptJson()
            ->post("{$this->base()}/synthesise", [
                'question' => $question,
                'chunks' => $chunks,
                // Decrypted only in ChatService just before this call; passed in
                // the body, never logged, never bound beyond the call stack.
                'provider_api_key' => $decryptedKey,
                'provider_config' => $providerConfig,
            ]);

        if (! $response->successful()) {
            // A non-2xx is an hr-ai/transport failure (not a provider error, which
            // comes back as a 200 envelope). Surface as an escalatable signal.
            return ['error' => 'synthesis_unavailable', 'detail' => "hr-ai /synthesise failed ({$response->status()})"];
        }

        return $response->json();
    }

    /**
     * Restate a small, already-verified list of facts as plain prose (Sprint
     * 7g fast-follow, ADR-0029). Deliberately NOT `synthesise()`: `$factsText`
     * is never a retrieved document/chunk, and hr-ai's `/explain` prompt
     * forbids citation markers and verbatim quoting entirely — see
     * `EscalationExplanationService`, the only caller.
     *
     * Returns { answer, trace_fragment } or, on a provider failure,
     * { error: 'provider_error', detail }. The caller treats either shape of
     * failure the same way: fall back to the deterministic sentences, never
     * throw, never guess.
     *
     * @param  array{provider:string,model:string,endpoint:?string}  $providerConfig
     * @return array<string,mixed>
     */
    public function explain(string $instruction, string $factsText, string $decryptedKey, array $providerConfig): array
    {
        $response = Http::withHeaders(['X-Internal-Token' => $this->token()])
            ->timeout(60)
            ->acceptJson()
            ->post("{$this->base()}/explain", [
                'instruction' => $instruction,
                'facts_text' => $factsText,
                // Decrypted only by the caller just before this call; passed in
                // the body, never logged, never bound beyond the call stack.
                'provider_api_key' => $decryptedKey,
                'provider_config' => $providerConfig,
            ]);

        if (! $response->successful()) {
            // A non-2xx is an hr-ai/transport failure (not a provider error, which
            // comes back as a 200 envelope). Surface as a fallback-triggering signal.
            return ['error' => 'explain_unavailable', 'detail' => "hr-ai /explain failed ({$response->status()})"];
        }

        return $response->json();
    }

    /**
     * Classify a question salary | prose | off_domain and (for a compound
     * question) return decomposed subqueries (Sprint 2b-2, ADR-0016). Uses the
     * SMALL/FAST router model. The decrypted key is passed in the body per call
     * (same envelope as synthesise()); hr-ai never persists it.
     *
     * Returns the router envelope { label, confidence, subqueries, reason,
     * trace_fragment } or, on a provider/transport failure, { error: ... } so
     * the caller (RouterService) can FAIL SAFE to the prose path.
     *
     * @param  array{provider:string,model:string,endpoint:?string}  $providerConfig
     * @return array<string,mixed>
     */
    public function route(string $question, string $decryptedKey, array $providerConfig): array
    {
        $response = Http::withHeaders(['X-Internal-Token' => $this->token()])
            ->timeout(60)
            ->acceptJson()
            ->post("{$this->base()}/route", [
                'question' => $question,
                'provider_api_key' => $decryptedKey,
                'provider_config' => $providerConfig,
            ]);

        if (! $response->successful()) {
            return ['error' => 'router_unavailable', 'detail' => "hr-ai /route failed ({$response->status()})"];
        }

        return $response->json();
    }

    /**
     * Per-claim entailment grounding of a prose answer against its CITED chunks
     * (Sprint 2b-2 §5) — the REAL grounding gate. Uses the CAPABLE answer model
     * (entailment is subtle; never the cheap router model). The decrypted key is
     * passed in the body per call; hr-ai never persists it.
     *
     * Returns { grounded, claims, ungrounded, trace_fragment } or, on a
     * provider/transport failure, { error: ... } so the caller escalates
     * (low_confidence) — never surfaces an unverified answer.
     *
     * @param  list<array{chunk_id:int,content:string,authority_level:?string,is_tabular:bool}>  $chunks
     * @param  array{provider:string,model:string,endpoint:?string}  $providerConfig
     * @return array<string,mixed>
     */
    public function ground(string $question, string $answer, array $chunks, string $decryptedKey, array $providerConfig): array
    {
        $response = Http::withHeaders(['X-Internal-Token' => $this->token()])
            ->timeout(120)
            ->acceptJson()
            ->post("{$this->base()}/ground", [
                'question' => $question,
                'answer' => $answer,
                'chunks' => $chunks,
                'provider_api_key' => $decryptedKey,
                'provider_config' => $providerConfig,
            ]);

        if (! $response->successful()) {
            return ['error' => 'grounding_unavailable', 'detail' => "hr-ai /ground failed ({$response->status()})"];
        }

        return $response->json();
    }

    /**
     * Propose document-level facets for an unresolved document (Sprint 7a,
     * ADR-0011/0020). hr-ai READS the page text hr-backend already holds and
     * RETURNS proposed facets + confidence bound to the CLOSED candidate
     * vocabulary — it writes nothing, never migrates. The decrypted answer-model
     * key is passed in the body per call (same envelope as synthesise()).
     *
     * Returns { facets, topics, raw_unmatched_values, overall_confidence,
     * trace_fragment } or, on a provider/transport failure, { error: ... } so the
     * caller (TagProposalService) leaves the doc in the human queue — a tagging
     * failure never blocks ingest nor surfaces an answerable doc.
     *
     * @param  array<string,mixed>  $candidateVocabulary
     * @param  array{provider:string,model:string,endpoint:?string}  $providerConfig
     * @return array<string,mixed>
     */
    public function proposeTags(int $documentId, string $pageText, array $candidateVocabulary, string $decryptedKey, array $providerConfig): array
    {
        $response = Http::withHeaders(['X-Internal-Token' => $this->token()])
            ->timeout(120)
            ->acceptJson()
            ->post("{$this->base()}/propose-tags", [
                'document_id' => $documentId,
                'page_text' => $pageText,
                'candidate_vocabulary' => $candidateVocabulary,
                'provider_api_key' => $decryptedKey,
                'provider_config' => $providerConfig,
            ]);

        if (! $response->successful()) {
            return ['error' => 'propose_unavailable', 'detail' => "hr-ai /propose-tags failed ({$response->status()})"];
        }

        return $response->json();
    }

    /**
     * Segment a multi-scope reference_source into per-scope facts (Sprint 7b-2,
     * ADR-0022). hr-ai READS the full concatenated /read-structured content + the
     * CLOSED candidate convenios (convenio-centric) + the approved topics, and
     * RETURNS an array of proposed facts (each bound to a real convenio_id, with
     * group_label/value/confidence/uncertainty/source_excerpt) — it writes
     * nothing, never migrates (ADR-0007). hr-backend persists each as
     * `ai_agent`/`needs_review`, upserting on the group_label-extended logical
     * key. The decrypted answer-model key is passed in the body per call.
     *
     * Returns { facts:[...], trace_fragment } or, on a provider/transport
     * failure, { facts:[], error } so the caller leaves the source unsegmented in
     * the human queue — a segmentation failure never blocks ingest nor surfaces
     * an answerable fact.
     *
     * @param  list<array<string,mixed>>  $candidateConvenios
     * @param  list<array<string,mixed>>  $candidateTopics
     * @param  array{provider:string,model:string,endpoint:?string}  $providerConfig
     * @return array<string,mixed>
     */
    public function segmentFacts(
        int $documentId,
        string $documentUuid,
        string $sourceFormat,
        string $pagesText,
        array $candidateConvenios,
        array $candidateTopics,
        string $decryptedKey,
        array $providerConfig,
    ): array {
        $response = Http::withHeaders(['X-Internal-Token' => $this->token()])
            ->timeout(180) // a multi-province segmentation is a larger LLM call
            ->acceptJson()
            ->post("{$this->base()}/segment-facts", [
                'document_id' => $documentId,
                'document_uuid' => $documentUuid,
                'source_format' => $sourceFormat,
                'pages_text' => $pagesText,
                'candidate_convenios' => $candidateConvenios,
                'candidate_topics' => $candidateTopics,
                'provider_api_key' => $decryptedKey,
                'provider_config' => $providerConfig,
            ]);

        if (! $response->successful()) {
            return ['facts' => [], 'error' => 'segment_unavailable', 'detail' => "hr-ai /segment-facts failed ({$response->status()})"];
        }

        return $response->json();
    }

    /**
     * Propose ONE convenio's group structure (Sprint 7f, ADR-0028). hr-ai READS
     * the convenio's own text plus its EXISTING categories (a closed set —
     * `group_code` is evidence only, never truth) plus the `group_label` strings
     * its verified facts already use, and RETURNS a proposed two-level tree with
     * a justifying excerpt per node. It writes nothing and never migrates
     * (ADR-0007).
     *
     * hr-backend persists every node as `ai_agent`/`needs_review`, so nothing
     * proposed here is comparable by the answer path until a human approves it,
     * and NO AI RUNS AT ANSWER TIME — this is a one-off structural read.
     *
     * Returns { groups:[...], trace_fragment } or, on a provider/transport
     * failure, { groups:[], error } so the caller leaves the convenio without a
     * proposal. That is the safe state: the existing matcher is untouched until a
     * human approves a tree, so a failure here degrades to "no structure yet",
     * never to a wrong scope.
     *
     * @param  array<string,mixed>  $convenio  id, name, numero, aliases, territory/sector names
     *                                         and aliases, and job_categories
     * @param  list<string>  $observedGroupLabels
     * @param  array{provider:string,model:string,endpoint:?string}  $providerConfig
     * @return array<string,mixed>
     */
    public function proposeGroups(
        array $convenio,
        string $pagesText,
        array $observedGroupLabels,
        string $decryptedKey,
        array $providerConfig,
    ): array {
        $response = Http::withHeaders(['X-Internal-Token' => $this->token()])
            ->timeout(180) // a whole convenio's text is a large prompt
            ->acceptJson()
            ->post("{$this->base()}/propose-groups", [
                'convenio' => $convenio,
                'pages_text' => $pagesText,
                'observed_group_labels' => $observedGroupLabels,
                'provider_api_key' => $decryptedKey,
                'provider_config' => $providerConfig,
            ]);

        if (! $response->successful()) {
            return ['groups' => [], 'error' => 'propose_groups_unavailable', 'detail' => "hr-ai /propose-groups failed ({$response->status()})"];
        }

        return $response->json();
    }

    /**
     * OCR one already-rendered page image (Sprint 7e, ADR-0026, review.md §2.1/
     * §2.2). hr-ai reuses the page image already written at `$imageKey` — never
     * re-renders — calls the vision provider, writes the S3 sidecar
     * (`documents/{uuid}/ocr/{page:04d}.json`, hr-ai's own privilege), and
     * returns the flattened text + quality/cost/engine metadata. The decrypted
     * key is passed in the body per call (same envelope as proposeTags/ground/
     * route); hr-ai never persists it.
     *
     * Never throws on a provider/transport failure — returns
     * `{error, detail}` instead (same convention as route()/ground()/
     * proposeTags()) so the caller (`OcrService`) can leave the page
     * `ocr_pending` for a retry rather than let a background job "fail" loudly
     * for what may just be a transient provider hiccup.
     *
     * @param  array{provider:string,model:string,endpoint:?string}  $providerConfig
     * @return array<string,mixed> hr-ai's /ocr-page envelope: {text, layout,
     *   bilingual, quality, quality_notes, cost_usd, sec_per_page, engine} or
     *   {error, detail}
     */
    public function ocrPage(string $documentUuid, int $pageNumber, string $imageKey, string $decryptedKey, array $providerConfig): array
    {
        $response = Http::withHeaders(['X-Internal-Token' => $this->token()])
            ->timeout(150) // measured 9-90 s/page (review.md §1.3/§1.5) — well under this
            ->acceptJson()
            ->post("{$this->base()}/ocr-page", [
                'document_uuid' => $documentUuid,
                'page_number' => $pageNumber,
                'image_key' => $imageKey,
                'provider_api_key' => $decryptedKey,
                'provider_config' => $providerConfig,
            ]);

        if (! $response->successful()) {
            return ['error' => 'ocr_unavailable', 'detail' => "hr-ai /ocr-page failed ({$response->status()})"];
        }

        return $response->json();
    }

    /**
     * Sprint 8, Step 5 (plan.md §1.3/§4.2, ADR-0030) — the ONLY hr-ai change
     * the whole sprint made. Read-only string[] -> vector[] (BGE-M3, unit-
     * normalized, so cosine similarity IS the dot product). No DB touch at
     * all on the hr-ai side. Capped server-side at
     * `EMBED_BATCH_MAX_TEXTS`/`settings.embed_batch_max_texts` (hr-ai,
     * default 256) — the caller (`QuestionClusteringService`) chunks a
     * larger distinct-question set into calls of that size itself.
     *
     * @param  list<string>  $texts
     * @return list<list<float>> one 1024-dim unit vector per input string, same order
     */
    public function embedBatch(array $texts): array
    {
        if ($texts === []) {
            return [];
        }

        $response = Http::withHeaders(['X-Internal-Token' => $this->token()])
            ->timeout(120)
            ->acceptJson()
            ->post("{$this->base()}/embed-batch", ['texts' => $texts]);

        if (! $response->successful()) {
            throw new RuntimeException("hr-ai /embed-batch failed ({$response->status()}): ".$response->body());
        }

        return $response->json('embeddings') ?? [];
    }
}
