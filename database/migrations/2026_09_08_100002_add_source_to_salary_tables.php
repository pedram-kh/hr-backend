<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 7e follow-up — salary-PDF-via-OCR (Option A, review.md §5).
 * `xlsx_native` (default) covers every salary table imported to date (a
 * directly-uploaded .xlsx); `ocr_pdf` marks a table whose numbers were read
 * off a SCANNED PDF via the OCR pinned-table-contract path and only reached
 * `salary:import` via a machine-derived `.xlsx` stand-in
 * (`documents.derived_from_document_id`). `salary:import` itself is NEVER
 * modified — this column is stamped by `salary:pdf-to-xlsx --mark-provenance`
 * as a deliberate, separate, human-gated step AFTER a reviewed import.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salary_tables', function (Blueprint $table) {
            $table->enum('source', ['xlsx_native', 'ocr_pdf'])
                ->default('xlsx_native')
                ->after('source_document_id');
        });
    }

    public function down(): void
    {
        Schema::table('salary_tables', function (Blueprint $table) {
            $table->dropColumn('source');
        });
    }
};
