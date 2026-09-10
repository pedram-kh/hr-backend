<?php

namespace Tests\Feature;

use App\Models\Convenio;
use App\Models\Employee;
use App\Models\Sector;
use App\Models\Territory;
use App\Support\DeflectionAnalytics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Sprint 8, Step 3 (plan.md §2, §11) — the DEFINITIONS test: `stats:deflection
 * --live` (the raw per-turn query, plan.md §2.2) and the rollup-table-backed
 * read (`stats:rollup` then `fromRollup()`) must return IDENTICAL numbers for
 * a fixed fixture period. This is the rollup's own correctness proof — if the
 * rollup ever drifts from the live definitions, this test catches it, not a
 * production dashboard.
 *
 * Also proves the individual definitions from plan.md §2.1 directly:
 * - needs_category is excluded from the deflection-rate denominator.
 * - the fifth path bucket ('prose') is synthesized correctly when `path` is
 *   absent but `retrieval_score_floor` is present.
 * - hr_agent messages never appear in the message_traces-keyed turn count.
 */
class Sprint8AnalyticsDefinitionsTest extends TestCase
{
    use RefreshDatabase;

    private Employee $employee;

    private Carbon $day;

    protected function setUp(): void
    {
        parent::setUp();
        $this->day = Carbon::create(2026, 9, 1, 12, 0, 0);
        $this->buildWorld();
    }

    private function buildWorld(): void
    {
        $territory = Territory::create(['code' => '01', 'name' => 'Álava', 'level' => 'provincial', 'aliases' => []]);
        $sector = Sector::create(['name' => 'Test Sector', 'aliases' => []]);
        $convenio = Convenio::create(['numero' => '01TESTX001', 'name' => 'Test Convenio', 'territory_id' => $territory->id, 'sector_id' => $sector->id]);
        $this->employee = Employee::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(), 'email' => 'w@example.com', 'full_name' => 'Worker',
            'convenio_id' => $convenio->id, 'territory_id' => $territory->id, 'employment_type' => 'full_time', 'status' => 'active',
        ]);

        $sessionId = DB::table('chat_sessions')->insertGetId([
            'uuid' => (string) \Illuminate\Support\Str::uuid(), 'employee_id' => $this->employee->id,
            'started_at' => $this->day, 'created_at' => $this->day, 'updated_at' => $this->day,
        ]);

        // Turn 1: prose, answered, authority_used = [official_convenio].
        $this->makeTurn($sessionId, 'prose q1', [
            'floor_decision' => ['outcome' => 'answer', 'retrieval_score_floor' => 0.9, 'authority_used' => ['official_convenio']],
        ]);
        // Turn 2: prose, escalated (weak retrieval).
        $this->makeTurn($sessionId, 'prose q2', [
            'floor_decision' => ['outcome' => 'escalate', 'retrieval_score_floor' => 0.1, 'escalation_reason' => 'low_confidence'],
        ]);
        // Turn 3: salary_sql, answered, no authority_used.
        $this->makeTurn($sessionId, 'salary q1', [
            'floor_decision' => ['outcome' => 'answer', 'path' => 'salary_sql'],
        ]);
        // Turn 4: salary_sql, needs_category — excluded from the ratio.
        $this->makeTurn($sessionId, 'salary q2', [
            'floor_decision' => ['outcome' => 'needs_category', 'path' => 'salary_sql'],
        ]);
        // Turn 5: reference_fact, answered, authority_used = [structured_reference].
        $this->makeTurn($sessionId, 'fact q1', [
            'floor_decision' => ['outcome' => 'answer', 'path' => 'reference_fact', 'authority_used' => ['structured_reference']],
        ]);

        // hr_agent reply — must NEVER be counted as a turn (no message_traces row).
        DB::table('chat_messages')->insert([
            'session_id' => $sessionId, 'role' => 'hr_agent', 'content' => 'human reply',
            'author_admin_id' => null, 'created_at' => $this->day, 'updated_at' => $this->day,
        ]);
    }

    private function makeTurn(int $sessionId, string $content, array $trace): void
    {
        $messageId = DB::table('chat_messages')->insertGetId([
            'session_id' => $sessionId, 'role' => 'assistant', 'content' => $content,
            'created_at' => $this->day, 'updated_at' => $this->day,
        ]);
        DB::table('message_traces')->insert([
            'message_id' => $messageId, 'trace' => json_encode($trace),
            'created_at' => $this->day, 'updated_at' => $this->day,
        ]);
    }

    public function test_definitions_match_expectations_directly(): void
    {
        $analytics = app(DeflectionAnalytics::class);
        $turns = $analytics->liveTurns($this->day->copy()->startOfDay(), $this->day->copy()->addDay(), []);

        $this->assertCount(5, $turns, 'exactly 5 message_traces turns exist — the hr_agent row must never appear here');

        $summary = $analytics->summarize($turns);

        $this->assertSame(3, $summary['answered']);
        $this->assertSame(1, $summary['escalated']);
        $this->assertSame(1, $summary['needs_category']);
        // deflection_rate = answered / (answered + escalated) = 3/4, needs_category excluded (§2.1).
        $this->assertSame(0.75, $summary['deflection_rate']);
        $this->assertSame(2, $summary['path_split']['prose'] ?? 0);
        $this->assertSame(2, $summary['path_split']['salary_sql'] ?? 0);
        $this->assertSame(1, $summary['path_split']['reference_fact'] ?? 0);
        $this->assertSame(1, $summary['authority_split']['official_convenio'] ?? 0);
        $this->assertSame(1, $summary['authority_split']['structured_reference'] ?? 0);
        $this->assertSame(3, $summary['authority_split']['none'] ?? 0);
    }

    public function test_live_and_rollup_agree_exactly(): void
    {
        $analytics = app(DeflectionAnalytics::class);

        $this->artisan('stats:rollup', ['--date' => $this->day->toDateString()])->assertExitCode(0);

        $liveSummary = $analytics->summarize($analytics->liveTurns($this->day->copy()->startOfDay(), $this->day->copy()->addDay(), []));
        $rollupSummary = $analytics->fromRollup($this->day->copy()->startOfDay(), $this->day->copy()->addDay(), []);

        $this->assertSame($liveSummary['answered'], $rollupSummary['answered']);
        $this->assertSame($liveSummary['escalated'], $rollupSummary['escalated']);
        $this->assertSame($liveSummary['needs_category'], $rollupSummary['needs_category']);
        $this->assertSame($liveSummary['deflection_rate'], $rollupSummary['deflection_rate']);
        // Key ORDER can legitimately differ (grouped in different SQL passes) —
        // the definitions test asserts VALUE agreement per key, not array order.
        ksort($liveSummary['path_split']);
        ksort($rollupSummary['path_split']);
        $this->assertSame($liveSummary['path_split'], $rollupSummary['path_split']);
        ksort($liveSummary['authority_split']);
        ksort($rollupSummary['authority_split']);
        $this->assertSame($liveSummary['authority_split'], $rollupSummary['authority_split']);
    }

    public function test_rollup_is_idempotent_on_rerun(): void
    {
        $this->artisan('stats:rollup', ['--date' => $this->day->toDateString()])->assertExitCode(0);
        $countAfterFirst = DB::table('analytics_daily_rollups')->count();

        $this->artisan('stats:rollup', ['--date' => $this->day->toDateString()])->assertExitCode(0);
        $countAfterSecond = DB::table('analytics_daily_rollups')->count();

        $this->assertSame($countAfterFirst, $countAfterSecond, 'a re-run for the same date must be a clean replace, not an accumulation');
    }

    public function test_hr_agent_replies_counted_separately_and_never_in_turns(): void
    {
        $analytics = app(DeflectionAnalytics::class);
        $hrAgent = $analytics->hrAgentReplies($this->day->copy()->startOfDay(), $this->day->copy()->addDay());

        $this->assertCount(1, $hrAgent);
        $this->assertSame(1, $hrAgent->first()->reply_count);
    }
}
