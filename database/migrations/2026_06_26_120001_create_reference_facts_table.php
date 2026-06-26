<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 7b-1 (ADR-0021) — Structured Reference Knowledge: the `reference_facts`
 * store. A NEW dedicated table generalizing the salary pattern (data-model §6):
 * scoped via the convenio (territory/sector derive — never stored), source-linked
 * for traceability, value + raw_values verbatim (nothing lost), queried-not-
 * embedded (ADR-0006 — NO chunks/embedding). The 7a inert-until-verified spine
 * (ADR-0020) is reused: a fact lands `needs_review` and is not answerable until a
 * human verifies; provenance is append-only in `tag_events` (no rewrite).
 *
 * INVARIANT 1 (authority-low, by construction): `authority_level` is an enum that
 * holds ONLY `structured_reference` — the column CANNOT physically store
 * `official_convenio`/`national_law`, so a reference fact can never outrank a
 * convenio. (StoreReferenceFactRequest also rejects 422 — the Sprint-6
 * reject-not-clamp belt-and-braces.)
 *
 * The `ai_agent` source lane is RESERVED but UNWRITTEN in 7b-1 — the manual path
 * is the only writer (it lights in 7b-2). Logical-key note (Q7): the AI upsert
 * key for 7b-2 is (convenio_id, topic_id, job_category_id, validity_start,
 * validity_end) — recorded for that sprint; NO hard unique constraint here, a
 * manual create is a single deliberate human action.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reference_facts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            // --- Scope (the load-bearing columns) -------------------------------
            // convenio fixes territory + sector (derived, never set independently),
            // exactly like salary_tables.convenio_id (data-model §5/§6).
            $table->foreignId('convenio_id')->constrained('convenios');
            // Nullable finer scope — a fact may be convenio-wide or category-specific.
            $table->foreignId('job_category_id')->nullable()->constrained('convenio_job_categories')->nullOnDelete();
            // Bind into APPROVED topics only (ADR-0011) — nullable.
            $table->foreignId('topic_id')->nullable()->constrained('topics')->nullOnDelete();

            // --- The fact ------------------------------------------------------
            // Single text rule (Q3 — no typed numeric columns: the rules are prose,
            // e.g. "periodo de prueba: 90/75 días"); raw_values preserves the
            // source phrasing/structure VERBATIM (the salary raw_values discipline).
            $table->text('value');
            $table->jsonb('raw_values')->nullable();

            // --- Validity / version (the periodo docx arrive in versions) ------
            $table->date('validity_start')->nullable();
            $table->date('validity_end')->nullable();

            // --- Authority (INVARIANT 1 — structurally bounded) ----------------
            // Only `structured_reference` is expressible. Adding LOWER/equal levels
            // later stays additive; official_convenio/national_law are NEVER added.
            $table->enum('authority_level', ['structured_reference'])->default('structured_reference');

            // --- Provenance / state (the 7a spine, ADR-0020) -------------------
            // `ai_agent` is RESERVED but unwritten in 7b-1 (manual path only writer).
            $table->enum('source', ['admin_manual', 'ai_agent'])->default('admin_manual');
            // Inert until verified: unverified = NOT answerable (the embedding-gate
            // analog; 7c teaches the engine to query only `verified` facts).
            $table->enum('status', ['needs_review', 'verified'])->default('needs_review');
            $table->foreignId('verified_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();

            // --- Source link (traceability — the salary source_document_id rule) -
            $table->foreignId('source_document_id')->nullable()->constrained('documents')->nullOnDelete();
            // Free-form locator in 7b-1 (Q9): "p.3 §2" / "sheet:smi26" / a paragraph
            // anchor. Structured machine anchors are deferred to 7b-2 if needed.
            $table->string('source_locator')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();

            // Query helpers for the lens hierarchy + the (future, 7c) answer query.
            $table->index(['convenio_id', 'topic_id']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reference_facts');
    }
};
