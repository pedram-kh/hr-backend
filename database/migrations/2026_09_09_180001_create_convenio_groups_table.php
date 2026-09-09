<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 7f (ADR-0028), migration 1 of 4 — STRUCTURED GROUP SCOPE.
 *
 * The vocabulary the reference-fact answer path will compare EXACTLY, replacing
 * `ReferenceFactAnswerService::factMatchesGroup`'s bare-digit regex. A node is
 * either a group (`parent_id IS NULL`) or a SUB-AREA of one (`parent_id` set) —
 * "área 5" of Grupo 2, which Hostelería Navarra prices differently from "resto
 * áreas" (90/75/60 días vs 60/45/30). A sub-area is not a job category and there
 * is no category row to hang it on, which is why this is its own table.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * THIS IS A NEW VOCABULARY, MINTED ONLY ON HUMAN APPROVAL (ADR-0028).
 *
 * `convenio_job_categories.group_code` is NOT the source of it and is NOT
 * migrated, normalized or backfilled here. Of its 94 rows, 72 hold nothing to
 * normalize and 9 of the remaining 22 hold a salary figure or a year; and the
 * sprint's critical convenio (21, Hostelería Navarra) has ZERO categories. So
 * `group_code` stays exactly where it is, doing its salary job — Phase 2 passes
 * it to the proposer as *evidence*, never as truth. Keeping the two tables apart
 * is also what guarantees a `salary:import` re-run can never disturb approved
 * group structure (Correction-salary-01 is one sprint old).
 * ────────────────────────────────────────────────────────────────────────────
 *
 * `status` and `source` are plain VARCHARs, not Laravel `enum`s — the same
 * deliberate choice `add_resolution_fields_to_reference_facts` documents. An
 * `enum` becomes a Postgres CHECK, and adding a value later then requires the
 * introspect-drop-readd dance 7b-2 had to perform for `reference_facts.status`.
 * The allowed sets live in the model + FormRequests, which are cheap to extend.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('convenio_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('convenio_id')->constrained('convenios')->cascadeOnDelete();

            // Self-reference. CASCADE (not nullOnDelete) is the correct direction:
            // an orphaned sub-area would silently become a TOP-LEVEL group, which
            // would change what the matcher compares. A sub-area cannot outlive
            // the group it slices.
            $table->foreignId('parent_id')->nullable()
                ->constrained('convenio_groups')->cascadeOnDelete();

            // The comparison key, produced by GroupCodeNormalizer at PROPOSE time
            // and never re-derived at answer time: '1', '2', 'area-5',
            // 'resto-areas', or — for the ~1/3 of real group labels that are prose
            // rather than numbers ('Obreros y subalternos') — the slugified label
            // itself. Tier 2 compares `convenio_groups.id`, an integer; this column
            // exists so a human and the CSV importer can address a node by name.
            $table->string('code_normalized');

            // As printed in the convenio: 'Grupo 2', 'área 5', 'resto áreas'.
            $table->string('label');

            // The line of convenio text that justifies this node. Required for
            // sub-areas (enforced in the service): a slice of a group that no
            // excerpt supports is exactly the kind of inference this sprint exists
            // to route through a human.
            $table->text('source_excerpt')->nullable();

            $table->string('status', 32)->default('needs_review');
            $table->string('source', 32);

            // Groups one proposer run together, so a whole batch can be reviewed,
            // re-proposed or discarded as a unit.
            $table->uuid('proposal_batch_id')->nullable();

            $table->foreignId('approved_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            // The picker (GET /admin/groups?convenio_id=) and the answer path both
            // read approved nodes for one convenio.
            $table->index(['convenio_id', 'status']);
            $table->index('parent_id');
            $table->index('proposal_batch_id');
        });

        // ── Uniqueness, split in two ON PURPOSE ──────────────────────────────
        // A single UNIQUE (convenio_id, parent_id, code_normalized) would NOT
        // constrain top-level groups at all: in Postgres NULLs are distinct, so
        // two rows with parent_id = NULL and the same code both satisfy it, and a
        // convenio could end up with two "Grupo 2" nodes — precisely the ambiguity
        // an exact matcher must never face. (`UNIQUE NULLS NOT DISTINCT` needs
        // PG 15+; two partial indexes say the same thing on any version and read
        // more explicitly.)
        DB::statement('CREATE UNIQUE INDEX convenio_groups_root_code_unique
            ON convenio_groups (convenio_id, code_normalized)
            WHERE parent_id IS NULL');

        // A sub-area code is unique only WITHIN its parent — 'todas-las-areas' may
        // legitimately exist under Grupo 1 and under Grupo 3.
        DB::statement('CREATE UNIQUE INDEX convenio_groups_child_code_unique
            ON convenio_groups (convenio_id, parent_id, code_normalized)
            WHERE parent_id IS NOT NULL');

        // ── Depth ≤ 2, enforced in the database ──────────────────────────────
        // A plain CHECK cannot express this: the rule is about the PARENT's row
        // ("a node with a parent must have a parent that is itself a root"), and
        // Postgres forbids subqueries in CHECK. A trigger is the only construct
        // that enforces it for real, and the invariant is load-bearing — Phase 3's
        // match rule is a single self-join that assumes a grandparent can never
        // exist. Application-level validation alone would leave a bulk insert or a
        // manual SQL fix able to create a three-level tree that silently changes
        // what the matcher sees.
        // CREATE OR REPLACE, not CREATE. `migrate:fresh` (and so `RefreshDatabase`
        // between test classes) drops all TABLES but not FUNCTIONS, so a plain
        // CREATE makes the second migration run fail with "function already
        // exists". Replacing is also the right semantics for a re-run.
        DB::statement('
            CREATE OR REPLACE FUNCTION convenio_groups_enforce_depth() RETURNS trigger AS $$
            DECLARE
                parent_parent bigint;
                parent_convenio bigint;
            BEGIN
                IF NEW.parent_id IS NULL THEN
                    RETURN NEW;
                END IF;

                SELECT parent_id, convenio_id INTO parent_parent, parent_convenio
                FROM convenio_groups WHERE id = NEW.parent_id;

                -- Looking UP: my parent must be a root.
                IF parent_parent IS NOT NULL THEN
                    RAISE EXCEPTION
                        \'convenio_groups is limited to two levels: node % cannot be a sub-area of %, which is itself a sub-area\',
                        COALESCE(NEW.code_normalized, \'?\'), NEW.parent_id;
                END IF;

                -- And looking DOWN, which the upward check alone does NOT cover:
                -- demoting a group that already has sub-areas would give those
                -- sub-areas a grandparent without ever touching their own rows.
                -- (Only reachable on UPDATE — a row being inserted has no children.)
                IF EXISTS (SELECT 1 FROM convenio_groups WHERE parent_id = NEW.id) THEN
                    RAISE EXCEPTION
                        \'convenio_groups is limited to two levels: node % cannot become a sub-area because it already has sub-areas of its own\',
                        NEW.id;
                END IF;

                -- A sub-area of a group in ANOTHER convenio would make the scope key
                -- meaningless, so the same trigger closes that hole while it is here.
                IF parent_convenio IS DISTINCT FROM NEW.convenio_id THEN
                    RAISE EXCEPTION
                        \'convenio_groups parent % belongs to convenio %, not %\',
                        NEW.parent_id, parent_convenio, NEW.convenio_id;
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql');

        DB::statement('
            CREATE TRIGGER convenio_groups_depth_check
            BEFORE INSERT OR UPDATE OF parent_id, convenio_id ON convenio_groups
            FOR EACH ROW EXECUTE FUNCTION convenio_groups_enforce_depth()');
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS convenio_groups_depth_check ON convenio_groups');
        DB::statement('DROP FUNCTION IF EXISTS convenio_groups_enforce_depth()');
        Schema::dropIfExists('convenio_groups');
    }
};
