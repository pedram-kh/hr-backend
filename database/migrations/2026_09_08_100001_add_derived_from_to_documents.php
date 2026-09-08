<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 7e follow-up — salary-PDF-via-OCR (Option A, review.md §5). `salary:
 * pdf-to-xlsx` never re-supplies the ORIGINAL PDF document row (its
 * `storage_path` keeps pointing at the scanned PDF — the admin "view
 * original" download must keep working); it instead creates a SEPARATE
 * `documents` row for the machine-derived `.xlsx` artifact so the EXISTING,
 * UNMODIFIED `salary:import` query (`storage_path like '%.xlsx'`) picks it up
 * naturally. This column is the only thing linking the two — distinct from
 * `predecessor_document_id` (version succession, a human/registry concept);
 * this is a mechanical derivation, always same convenio, never surfaced as a
 * lineage badge.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->foreignId('derived_from_document_id')
                ->nullable()
                ->after('predecessor_document_id')
                ->constrained('documents')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('derived_from_document_id');
        });
    }
};
