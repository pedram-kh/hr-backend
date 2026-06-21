<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 1 — idempotency key for re-upload (plan §9 Q5).
 *
 * sha256 of the file bytes is the primary idempotency key for ingestion;
 * (source_filename + convenio_id) is the fallback. Nullable so legacy/internal
 * documents without an uploaded file are still valid.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->string('content_hash', 64)->nullable()->after('storage_path')->index();
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn('content_hash');
        });
    }
};
