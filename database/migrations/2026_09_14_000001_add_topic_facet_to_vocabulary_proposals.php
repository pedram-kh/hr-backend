<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Sprint 10c, post-CP-B decision — the topic vocabulary lane.
 *
 * `vocabulary_proposals.facet` (2026_06_26_100001) was a Postgres `enum()`
 * column, i.e. a plain `string` + a CHECK constraint restricting it to
 * territory/sector/convenio — the three facets that existed when Sprint 7a
 * generalized the topics propose/approve pattern. `topic` now joins
 * `VocabularyProposalService::FACET_MODEL` (reusing the SAME lane rather
 * than a bespoke one, per ADR-0011's "the gate matters, not the mechanism"),
 * so the check constraint widens to admit it. Postgres has no
 * `ALTER TYPE ... ADD VALUE` equivalent reachable via Laravel's enum() schema
 * builder here (it's a CHECK, not a native enum type) — drop and recreate is
 * the standard, safe move for a CHECK constraint. Additive only: no existing
 * row's `facet` value is touched, and the three original values remain valid.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE vocabulary_proposals DROP CONSTRAINT vocabulary_proposals_facet_check');
        DB::statement("ALTER TABLE vocabulary_proposals ADD CONSTRAINT vocabulary_proposals_facet_check CHECK (facet IN ('territory','sector','convenio','topic'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE vocabulary_proposals DROP CONSTRAINT vocabulary_proposals_facet_check');
        DB::statement("ALTER TABLE vocabulary_proposals ADD CONSTRAINT vocabulary_proposals_facet_check CHECK (facet IN ('territory','sector','convenio'))");
    }
};
