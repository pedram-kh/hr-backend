<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 8, Step 3 (plan.md §2.4, ADR-0030): `analytics_daily_rollups` — the
 * deflection/outcome rollup table. One row per (date, territory, sector,
 * convenio, path, authority_used_key, outcome) combination that actually
 * occurred — sparse by construction (no row for a zero-turn combination).
 * Every dimension besides `date`/`path`/`outcome` is nullable so ONE rollup
 * run writes both the granular rows and the "all scopes" totals row (a null
 * territory/sector/convenio row) via Postgres `GROUPING SETS` in one pass.
 *
 * Populated by `stats:rollup --date=YYYY-MM-DD` (idempotent per date —
 * delete-then-insert, never upsert-per-row, so a re-run is a clean replace).
 * Read by `stats:deflection` by default; `--live` bypasses this table and
 * re-runs the raw per-turn query, which is the rollup's own correctness test.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analytics_daily_rollups', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->foreignId('territory_id')->nullable()->constrained('territories')->cascadeOnDelete();
            $table->foreignId('sector_id')->nullable()->constrained('sectors')->cascadeOnDelete();
            $table->foreignId('convenio_id')->nullable()->constrained('convenios')->cascadeOnDelete();
            $table->string('path', 64)->nullable(); // salary_sql | reference_fact | reference_fact_composition | salary_prose_crosspath | prose
            $table->string('authority_used_key', 128)->nullable(); // sorted+joined authority_used array, e.g. "official_convenio+structured_reference"
            $table->string('outcome', 32); // answer | escalate | needs_category | unknown
            $table->unsignedInteger('turn_count')->default(0);
            $table->timestamps();

            $table->index(['date', 'territory_id', 'sector_id', 'convenio_id'], 'analytics_daily_rollups_scope_idx');
            $table->index(['date', 'path', 'outcome'], 'analytics_daily_rollups_path_outcome_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_daily_rollups');
    }
};
