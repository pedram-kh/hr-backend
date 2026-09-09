<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 7f (ADR-0028), migration 4 of 4 — the employee's own group node.
 *
 * ONE nullable column. Because `convenio_groups` is a two-level tree, no separate
 * `sub_area_id` is needed: the employee points at whichever node describes them,
 * a top-level group ("Grupo 1") or a sub-area ("Grupo 2 › resto áreas").
 *
 * ⚠ BLANK IS THE DEFAULT AND BLANK MEANS UNRESOLVED → THE MATCHER ESCALATES.
 * That is exactly today's behaviour, so this migration changes no answer on its
 * own. All 14 seeded employees and all ~1,500 real ones read null after it runs;
 * there is no backfill, and there is deliberately no derivation from
 * `convenio_job_categories.group_code` (of 94 rows, not one holds a clean code on
 * a real category, and convenio 21 has no categories at all — see ADR-0028).
 *
 * A group is NEVER auto-assigned. Where the employee's category maps to exactly
 * one approved node via `convenio_group_categories`, the directory form PRE-FILLS
 * the picker and labels it "sugerido a partir de la categoría — confirma";
 * nothing is written until an admin saves. `nullOnDelete` mirrors the existing
 * `job_category_id` handling, and changing an employee's convenio clears this
 * field the same way it clears the category.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->foreignId('convenio_group_id')->nullable()->after('job_category_id')
                ->constrained('convenio_groups')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropConstrainedForeignId('convenio_group_id');
        });
    }
};
