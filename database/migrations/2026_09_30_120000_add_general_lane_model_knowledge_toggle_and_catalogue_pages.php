<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Slice 13c (plan.md §2.6, §6) — additive only.
 *
 *  1. `guardrail_config.general_lane_model_knowledge_enabled`: the Guardarraíles RESTRICT-only toggle for model knowledge
 *     as a lane source. NULL = no admin override (the env baseline governs); effective = lane enabled AND env baseline
 *     AND (admin ?? true) — an admin can only switch it OFF, never force it on.
 *  2. `guardrail_catalogue_pages`: the official-page catalogue the lane may fetch, as DATA so HR can add a page without a
 *     deploy (content decision: human). Soft-disable, never a hard delete (history intact), mirroring
 *     `guardrail_blocked_topics`. The domain ALLOWLIST is not here — it stays in config/env and is enforced at write
 *     (422) AND at fetch (hr-ai SSRF guard); a row can never widen it. The 5 Sprint-13 pages are seeded from
 *     `config('hr.general_lane.sources')` (idempotent, `baseline = true`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('guardrail_config', function (Blueprint $table) {
            $table->boolean('general_lane_model_knowledge_enabled')->nullable()->after('general_lane_enabled');
        });

        Schema::create('guardrail_catalogue_pages', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('title');
            $table->text('url');
            // list of lower-case topic terms (accented and unaccented spellings are both listed: the local match is accent-sensitive)
            $table->json('topics');
            $table->boolean('enabled')->default(true);
            // seeded from config (the Sprint-13 pages) rather than added by an admin
            $table->boolean('baseline')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();

            $table->index('enabled');
        });

        $now = now();
        foreach ((array) config('hr.general_lane.sources', []) as $page) {
            if (! isset($page['id'], $page['url'], $page['title'])) {
                continue;
            }
            DB::table('guardrail_catalogue_pages')->updateOrInsert(
                ['slug' => $page['id']],
                [
                    'title' => $page['title'],
                    'url' => $page['url'],
                    'topics' => json_encode(array_values($page['topics'] ?? []), JSON_UNESCAPED_UNICODE),
                    'enabled' => true,
                    'baseline' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('guardrail_catalogue_pages');

        Schema::table('guardrail_config', function (Blueprint $table) {
            $table->dropColumn('general_lane_model_knowledge_enabled');
        });
    }
};
