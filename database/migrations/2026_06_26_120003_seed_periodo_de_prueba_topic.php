<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Sprint 7b-2 (Q6) — seed the approved `periodo de prueba` topic.
 *
 * The structured-reference fixtures are all *periodo de prueba* rules, so the
 * segmentation agent must be able to BIND `reference_facts.topic_id` to a real
 * approved topic (the agent never MINTS a topic — ADR-0011; this seed is the
 * deliberate admin action that creates the vocabulary value). Idempotent and
 * additive; lockstep with `TopicSeeder` (fresh installs get it there).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('topics')->updateOrInsert(
            ['name' => 'periodo de prueba'],
            [
                'status' => 'approved',
                'proposed_by' => null,
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        // Only remove it if no fact references it (avoid orphaning a bound topic).
        $id = DB::table('topics')->where('name', 'periodo de prueba')->value('id');
        if ($id !== null && ! DB::table('reference_facts')->where('topic_id', $id)->exists()) {
            DB::table('topics')->where('id', $id)->delete();
        }
    }
};
