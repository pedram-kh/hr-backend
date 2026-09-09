<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 7f (ADR-0028), migration 3 of 4 — what scope a reference fact applies to.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * MANY-TO-MANY, BECAUSE REAL FACTS ARE COMPOUND. This is forced by the data,
 * not chosen for generality.
 *
 * The sprint's headline fact — verified by a human on staging as fact #44 —
 * reads "Grupo 1 (todas las áreas) y Grupo 2 (área 5)" = 90/75/60 días. It spans
 * TWO DIFFERENT GROUPS. The spec's original shape (nullable `group_id` +
 * `sub_area_id` columns on `reference_facts`) cannot hold it: with one pair the
 * fact stays unbound, so it is not group-matchable, so a Grupo 1 employee
 * escalates — and acceptance test (c) requires that employee to be told
 * 90/75/60. The single-pair design fails the sprint's own example.
 *
 * The rejected alternative was splitting a compound fact into two facts at
 * approval time, which honours "one fact per scope" (ADR-0021) more literally.
 * It was rejected because it manufactures two facts from one source line and
 * mutates a fact a human already verified. Binding is additive; splitting is not.
 * ────────────────────────────────────────────────────────────────────────────
 *
 * How the verified Hostelería Navarra trio binds:
 *
 *   #44 "Grupo 1 (todas las áreas) y Grupo 2 (área 5)"  → TWO rows: {G1}, {G2/área 5}
 *   #45 "Grupo 2 (resto áreas)"                          → one row: {G2/resto áreas}
 *   #46 "Grupo 3 (todas las áreas)"                      → one row: {G3}
 *
 * Tier 2 then reads: the fact matches if ANY of its bound scope rows matches the
 * employee's node. Tests (a)–(d) fall out of that directly.
 *
 * `reference_facts` GAINS NO COLUMNS in this sprint. `group_label` is untouched
 * — still the printed provenance string, still part of `ReferenceFact::LOGICAL_KEY`,
 * still what 7b-2's segmentation upsert keys on. A fact with no rows here is
 * simply UNBOUND, which is the state every one of the 88 facts on staging is in
 * today and the state Phase 3 must escalate on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reference_fact_group_scopes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reference_fact_id')->constrained('reference_facts')->cascadeOnDelete();
            $table->foreignId('convenio_group_id')->constrained('convenio_groups')->cascadeOnDelete();

            // Binding is ALWAYS a human act — there is no automatic binder, the same
            // way there is no automatic duplicate resolver (7d). Nullable + null-on-delete
            // matches `reference_facts.resolved_by`/`approved_by`: deleting an admin
            // must not cascade away the binding itself, and the append-only
            // `tag_events` row carries the full narrative regardless.
            $table->foreignId('bound_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('bound_at')->nullable();
            $table->timestamps();

            $table->unique(['reference_fact_id', 'convenio_group_id']);

            // Tier 2 walks from the employee's node to the facts bound to it.
            $table->index('convenio_group_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reference_fact_group_scopes');
    }
};
