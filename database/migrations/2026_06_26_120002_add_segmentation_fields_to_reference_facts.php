<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 7b-2 (ADR-0022) — the AI segmentation agent's additive columns on
 * `reference_facts`. The `ai_agent` lane reserved in 7b-1 is now written by the
 * agent; these fields carry the proposal's metadata + the review-UX defenses.
 * Additive only (ADR-0007): no existing column changes shape, no data is moved.
 *
 * THE BLOCKER FIX (Q1) — `group_label`. `convenio_job_categories` is salary-
 * derived and unseeded for the periodo convenios, so `job_category_id` is usually
 * null. Without a group discriminator, every "Grupo 1/2/3" fact for the same
 * (convenio, topic, validity) would collide on the logical key and the upsert
 * would clobber them. `group_label` (the group AS WRITTEN — "Grupo 1",
 * "Grupo 2 (resto áreas)", "Obreros y subalternos") is folded into the upsert key
 * so each per-group fact persists distinctly. It is stored as-seen; matching
 * normalizes (trim/collapse/case-fold) in the persist service.
 *   Extended logical key: (convenio_id, topic_id, job_category_id, group_label,
 *   validity_start, validity_end) — see ReferenceFact::LOGICAL_KEY.
 *
 * The other fields:
 *  - `confidence`     — the agent's scope-assignment confidence (queue sort).
 *  - `uncertainty`    — { field, reason } structured flag (scope unclear /
 *                       compound group / possible version) — sortable, distinct.
 *  - `source_excerpt` — the EXACT source line(s) + header trail (the review-UX
 *                       defense: the reviewer checks "did the source say Álava?").
 *  - `proposal_batch_id` — groups one agent run (eval + re-segment idempotency).
 *  - `duplicate_of_id`   — the obvious-duplicate FLAG (a logical-key collision
 *                       with a DIFFERING value across versions). Signal ONLY —
 *                       resolution is 7d; no winner is ever picked here.
 *
 * The `status` enum gains `rejected` (Q5): a rejected proposal is auditable and
 * excluded from the queue WITHOUT deletion (over a soft-delete timestamp). Uses
 * the established introspect-drop-readd CHECK idiom (Postgres enum-as-CHECK).
 */
return new class extends Migration
{
    /** status values before this migration (7b-1). */
    private const STATUS_PRIOR = ['needs_review', 'verified'];

    /** status values after this migration (7b-2 adds `rejected`). */
    private const STATUS_CURRENT = ['needs_review', 'verified', 'rejected'];

    public function up(): void
    {
        Schema::table('reference_facts', function (Blueprint $table) {
            // The blocker fix (Q1) — the group as written, a first-class identity
            // discriminator folded into the upsert key (NOT buried in raw_values).
            $table->string('group_label')->nullable()->after('job_category_id');

            // The agent's proposal metadata.
            $table->decimal('confidence', 4, 3)->nullable()->after('raw_values');
            // { field: 'scope'|'version'|'group'|..., reason: '...' } — structured
            // so "scope unclear" / "compound group" / "possible version" are
            // distinguishable and sortable (Q3). Null = the agent was confident.
            $table->jsonb('uncertainty')->nullable()->after('confidence');

            // The review-UX defense — the exact source line(s) with the header
            // trail ("ALAVA › COEAS ALAVA › Grupo 1: Cinco meses").
            $table->text('source_excerpt')->nullable()->after('source_locator');

            // One agent run (eval grouping + re-segment idempotency audit).
            $table->uuid('proposal_batch_id')->nullable()->after('source_excerpt');

            // The obvious-duplicate flag (Q4): a logical-key collision with a
            // DIFFERING value (a different source version). Signal only — 7d
            // resolves; nothing is merged or retired here.
            $table->foreignId('duplicate_of_id')->nullable()->after('proposal_batch_id')
                ->constrained('reference_facts')->nullOnDelete();

            $table->index('proposal_batch_id');
            $table->index(['source', 'status']); // the uncertain-first review queue
        });

        // status enum gains `rejected` (Q5).
        $this->replaceStatusCheck(self::STATUS_CURRENT);
    }

    public function down(): void
    {
        // A rejected row would violate the reverted CHECK; normalize it first so
        // down() is safe (it becomes needs_review — the inert default).
        if (DB::getDriverName() === 'pgsql') {
            DB::table('reference_facts')->where('status', 'rejected')->update(['status' => 'needs_review']);
        }
        $this->replaceStatusCheck(self::STATUS_PRIOR);

        Schema::table('reference_facts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('duplicate_of_id');
            $table->dropIndex(['proposal_batch_id']);
            $table->dropIndex(['source', 'status']);
            $table->dropColumn(['group_label', 'confidence', 'uncertainty', 'source_excerpt', 'proposal_batch_id']);
        });
    }

    /**
     * Drop whatever CHECK constraint currently governs reference_facts.status
     * (introspected, not guessed) and re-add it with the given value set. The
     * established Postgres enum-as-CHECK idiom (cf. the escalation_cards.reason
     * migrations). Additive + idempotent.
     *
     * @param  list<string>  $values
     */
    private function replaceStatusCheck(array $values): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return; // CHECK-constraint enum handling here is Postgres-specific.
        }

        $existing = DB::selectOne(
            'SELECT con.conname FROM pg_constraint con '
            .'JOIN pg_class rel ON rel.oid = con.conrelid '
            ."WHERE rel.relname = 'reference_facts' AND con.contype = 'c' "
            ."AND pg_get_constraintdef(con.oid) LIKE '%status%' LIMIT 1"
        );

        if ($existing !== null) {
            DB::statement('ALTER TABLE reference_facts DROP CONSTRAINT IF EXISTS '.$existing->conname);
        }

        $list = implode(', ', array_map(fn ($v) => "'".$v."'", $values));
        DB::statement(
            'ALTER TABLE reference_facts ADD CONSTRAINT reference_facts_status_check '
            ."CHECK (status::text = ANY (ARRAY[$list]::text[]))"
        );
    }
};
