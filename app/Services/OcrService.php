<?php

namespace App\Services;

use App\Models\AnswerModelSetting;
use App\Models\DocumentPage;
use Illuminate\Support\Facades\Log;

/**
 * The single per-page OCR call (Sprint 7e, ADR-0026, review.md §2.1). ONE
 * implementation, TWO callers, chosen by whether the caller has an HTTP
 * request/response deadline:
 *
 *  - Ingest-time: `OcrPage::handle()` (queued — has no deadline of its own once
 *    dispatched, but the ORIGINAL upload/ingest request that triggered it does,
 *    which is exactly why OCR itself never runs inline in that request).
 *  - Backfill: `documents:ocr-backfill` (a synchronous CLI loop — no web
 *    deadline at all, matching `ChunksEmbed`'s own synchronous `/embed` call).
 *
 * "Queued/async" is a constraint on the WEB REQUEST PATH, not a blanket rule
 * that every OCR call everywhere must go through the queue — this service
 * itself is identical either way; only the caller's context decides sync vs.
 * queued (review.md §2.1's own framing).
 *
 * hr-backend remains the only DB writer — this is the ONLY place that writes
 * `document_pages.text`/`extraction_source`/`ocr_*` after ingest.
 */
class OcrService
{
    public function __construct(private ExtractionClient $ai) {}

    /**
     * OCR one page and persist the result. Never throws — a provider/transport
     * failure leaves the page `ocr_pending` (untouched) for a future retry
     * (the next ingest/backfill run, or a manual re-dispatch), exactly like
     * `TagProposalService::propose()` leaves a document in the human queue on
     * a provider failure rather than raising into the caller.
     *
     * @return array<string,mixed> {status: 'ok'|'skipped'|'error', ...}
     */
    public function ocrOnePage(DocumentPage $page): array
    {
        // Defensive: only ever OCR a page that is actually still pending — a
        // page already OCR'd (or one that never needed it) is left alone. This
        // makes a duplicate/late dispatch of the same OcrPage job a safe no-op,
        // never a double-charge for the vision call.
        if ($page->extraction_source !== 'ocr_pending') {
            return ['status' => 'skipped', 'reason' => 'not_pending', 'extraction_source' => $page->extraction_source];
        }

        if (empty($page->image_path)) {
            // Should not happen — /extract always renders a page image before
            // deciding extraction_source (extract.py:37-38) — but never OCR
            // against a page with nothing to read.
            Log::warning('ocr page skipped: no image_path', ['document_id' => $page->document_id, 'page_number' => $page->page_number]);

            return ['status' => 'skipped', 'reason' => 'no_image'];
        }

        $settings = AnswerModelSetting::current();
        if (! $settings->isConfigured()) {
            // No key → no OCR call. The page simply stays ocr_pending until a
            // key is configured and this is retried (same posture as
            // TagProposalService's answer_model_not_configured skip).
            return ['status' => 'skipped', 'reason' => 'answer_model_not_configured'];
        }

        $document = $page->document; // already loaded by callers (OcrPage::handle, the backfill loop)
        $providerConfig = [
            'provider' => config('services.hr_ai.ocr_provider', 'claude'),
            'model' => config('services.hr_ai.ocr_model', 'claude-opus-5'),
            'endpoint' => config('services.hr_ai.ocr_endpoint'),
        ];

        $key = $settings->decryptKey();
        $result = $this->ai->ocrPage($document->uuid, $page->page_number, $page->image_path, $key, $providerConfig);
        unset($key); // drop the plaintext as soon as the call returns

        if (isset($result['error'])) {
            Log::warning('ocr page: provider failure (page left ocr_pending for retry)', [
                'document_id' => $page->document_id,
                'page_number' => $page->page_number,
                'model' => $providerConfig['model'],
                'error' => $result['error'], // never the key
            ]);

            return ['status' => 'error', 'reason' => $result['error']];
        }

        $page->update([
            'text' => (string) ($result['text'] ?? ''),
            'extraction_source' => 'ocr',
            'ocr_quality' => $result['quality'] ?? null,
            'ocr_engine' => $result['engine'] ?? $providerConfig['model'],
            'ocr_cost_usd' => $result['cost_usd'] ?? null,
            // Adjustment 1 (review.md §2.4/§2.6) — guidance marker only, never a
            // second gate. True only for a two-column page whose two columns'
            // languages differ (hr-ai's own derivation, app/providers/claude.py).
            'ocr_bilingual' => (bool) ($result['bilingual'] ?? false),
        ]);

        Log::info('ocr page completed', [
            'document_id' => $page->document_id,
            'page_number' => $page->page_number,
            'model' => $providerConfig['model'],
            'cost_usd' => $result['cost_usd'] ?? null,
            'sec_per_page' => $result['sec_per_page'] ?? null,
            'quality' => $result['quality'] ?? null,
        ]);

        return [
            'status' => 'ok',
            'quality' => $result['quality'] ?? null,
            'cost_usd' => $result['cost_usd'] ?? null,
            'sec_per_page' => $result['sec_per_page'] ?? null,
            'bilingual' => (bool) ($result['bilingual'] ?? false),
        ];
    }
}
