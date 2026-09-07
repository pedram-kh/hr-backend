<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 7e (ADR-0026) — OCR fallback provenance. Purely additive: every
 * existing row defaults to `extraction_source = 'text_layer'` (the Sprint-1
 * behavior, unchanged), and every other new column is nullable.
 *
 * `extraction_source` is a THREE-valued enum, not the two the plan's own prose
 * names (`text_layer|ocr`) — the third, transient value `ocr_pending` is the
 * literal marker the plan's own build prompt calls for ("`/extract` ... marks
 * the page as `ocr_pending`", review.md §2.4): without it there is no DB-visible
 * way to distinguish "queued for OCR, not done yet" from "permanently
 * text-less" (OCR off, or past the page cap), and the queued job has nothing to
 * select on. It is the same two DESTINATION states the plan describes, with one
 * transient state in between while the async job is in flight.
 *
 * `documents.ocr_pages_count` is deliberately NOT a column here — it is
 * DERIVED (the plan's own word) via a `selectRaw` in `DocumentController::index()`,
 * matching the existing `pages_total`/`pages_with_text`/`has_open_review` pattern.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_pages', function (Blueprint $table) {
            $table->enum('extraction_source', ['text_layer', 'ocr_pending', 'ocr'])
                ->default('text_layer')
                ->after('image_path');
            $table->decimal('ocr_quality', 4, 3)->nullable()->after('extraction_source');
            $table->string('ocr_engine')->nullable()->after('ocr_quality');
            $table->decimal('ocr_cost_usd', 8, 4)->nullable()->after('ocr_engine');
            // Reviewer-guidance marker only (Adjustment 1, review.md §2.4/§2.6) —
            // never a second gate, never held out of embedding on its own. True
            // only for a two-column OCR'd page whose two columns' languages
            // differ (hr-ai's `bilingual` derivation, `app/providers/claude.py`).
            $table->boolean('ocr_bilingual')->nullable()->after('ocr_cost_usd');
        });
    }

    public function down(): void
    {
        Schema::table('document_pages', function (Blueprint $table) {
            $table->dropColumn(['extraction_source', 'ocr_quality', 'ocr_engine', 'ocr_cost_usd', 'ocr_bilingual']);
        });
    }
};
