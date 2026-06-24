<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 4 (additive): make the employee chat TWO-WAY by allowing a human
 * HR-agent message in the same session, distinguished from a bot answer.
 *
 *  - `chat_messages.role` gains `hr_agent` (a human turn — NOT a synthesised
 *    answer, so it carries no message_traces / message_citations row).
 *  - a nullable `author_admin_id` FK → admins records WHICH admin wrote an
 *    `hr_agent` message (NULL for `user`/`assistant` rows). This is how the
 *    employee-facing reply is attributed and never mistaken for the bot.
 *
 * The role enum is a Postgres CHECK constraint (Laravel `enum()`); we add the
 * value with the proven introspect-drop-readd idiom from
 * `…_add_salary_coverage_gap_to_escalation_cards_reason` — reversible,
 * idempotent, additive-only. The historical `…_create_chat_messages_table`
 * migration is left untouched (committed migrations are immutable).
 */
return new class extends Migration
{
    /** Value set before this migration. */
    private const PRIOR = ['user', 'assistant'];

    /** Value set after this migration. */
    private const CURRENT = ['user', 'assistant', 'hr_agent'];

    public function up(): void
    {
        if (! Schema::hasColumn('chat_messages', 'author_admin_id')) {
            Schema::table('chat_messages', function (Blueprint $table) {
                $table->foreignId('author_admin_id')->nullable()->after('role')
                    ->constrained('admins')->nullOnDelete();
            });
        }

        $this->replaceRoleCheck(self::CURRENT);
    }

    public function down(): void
    {
        // Restore the two-value enum first (so dropping the column can't leave a
        // stray hr_agent row violating the prior constraint).
        $this->replaceRoleCheck(self::PRIOR);

        if (Schema::hasColumn('chat_messages', 'author_admin_id')) {
            Schema::table('chat_messages', function (Blueprint $table) {
                $table->dropConstrainedForeignId('author_admin_id');
            });
        }
    }

    /**
     * Drop whatever CHECK constraint currently governs chat_messages.role
     * (introspected, not guessed) and re-add it with the given value set.
     *
     * @param  list<string>  $values
     */
    private function replaceRoleCheck(array $values): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return; // CHECK-constraint enum handling here is Postgres-specific.
        }

        $existing = DB::selectOne(
            'SELECT con.conname FROM pg_constraint con '
            .'JOIN pg_class rel ON rel.oid = con.conrelid '
            ."WHERE rel.relname = 'chat_messages' AND con.contype = 'c' "
            ."AND pg_get_constraintdef(con.oid) LIKE '%role%' LIMIT 1"
        );

        if ($existing !== null) {
            DB::statement('ALTER TABLE chat_messages DROP CONSTRAINT IF EXISTS '.$existing->conname);
        }

        $list = implode(', ', array_map(fn ($v) => "'".$v."'", $values));
        DB::statement(
            'ALTER TABLE chat_messages ADD CONSTRAINT chat_messages_role_check '
            ."CHECK (role::text = ANY (ARRAY[$list]::text[]))"
        );
    }
};
