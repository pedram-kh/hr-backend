<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 8, Step 2 (plan.md §5.5, ADR-0030): `coverage_snapshots` — one row
 * per (date, convenio_id, knowledge_type), the ✓/✗ + reason code at that
 * point in time. Written by `coverage:snapshot` (the nightly job, alongside
 * `stats:rollup`). Kept SEPARATE from `analytics_daily_rollups` on purpose —
 * coverage is a state-of-the-world snapshot (sparse-but-dense per convenio,
 * re-derivable any day), not an event-count aggregate; mixing the two schemas
 * would force nullable columns neither needs.
 *
 * "Gaps closed per week" = count(rows that were ✗ last week, ✓ this week) —
 * a self-join on this table, no separate trend table needed (plan.md §5.5).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coverage_snapshots', function (Blueprint $table) {
            $table->id();
            $table->date('snapshot_date');
            $table->foreignId('convenio_id')->constrained('convenios')->cascadeOnDelete();
            $table->string('knowledge_type', 32); // 'prose' | 'salary' | 'facts' | 'rulings'
            $table->boolean('covered');
            $table->boolean('amendment_only')->default(false);
            $table->boolean('group_only')->default(false);
            $table->string('reason_code', 64)->nullable();
            $table->text('detail')->nullable();
            $table->integer('headcount')->default(0);
            $table->timestamps();

            $table->unique(['snapshot_date', 'convenio_id', 'knowledge_type'], 'coverage_snapshots_date_convenio_type_unique');
            $table->index(['convenio_id', 'knowledge_type', 'snapshot_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coverage_snapshots');
    }
};
