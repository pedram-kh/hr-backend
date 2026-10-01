<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Convenio;
use App\Models\Employee;
use App\Models\Sector;
use App\Models\Territory;
use App\Support\DeflectionAnalytics;
use App\Support\QuestionClusteringService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Slice 13e (plan.md §5) — what HR sees: the `declined` figure (excluded from the deflection denominator), declines per day
 * (live == rollup), the "declined this week" ranking, and Historial's `declined` bucket, flag and badge input.
 */
class Sprint13eDeclineReportingTest extends TestCase
{
    use RefreshDatabase;

    private Employee $employee;

    private Carbon $day;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->day = Carbon::create(2026, 9, 1, 12, 0, 0);
        $territory = Territory::create(['code' => '01', 'name' => 'Álava', 'level' => 'provincial', 'aliases' => []]);
        $sector = Sector::create(['name' => 'Test Sector', 'aliases' => []]);
        $convenio = Convenio::create(['numero' => '01TESTD001', 'name' => 'Test Convenio', 'territory_id' => $territory->id, 'sector_id' => $sector->id]);
        $this->employee = Employee::create([
            'uuid' => (string) Str::uuid(), 'email' => 'rep@example.com', 'full_name' => 'Reporting Worker',
            'convenio_id' => $convenio->id, 'territory_id' => $territory->id, 'employment_type' => 'full_time', 'status' => 'active',
        ]);
    }

    private function newSession(): int
    {
        return DB::table('chat_sessions')->insertGetId([
            'uuid' => (string) Str::uuid(), 'employee_id' => $this->employee->id,
            'started_at' => $this->day, 'last_activity_at' => $this->day, 'created_at' => $this->day, 'updated_at' => $this->day,
        ]);
    }

    /** One user question + the assistant turn that answered it. */
    private function turn(int $sessionId, string $question, string $outcome, ?Carbon $at = null): int
    {
        $at ??= $this->day;
        DB::table('chat_messages')->insert(['session_id' => $sessionId, 'role' => 'user', 'content' => $question, 'created_at' => $at, 'updated_at' => $at]);
        $id = DB::table('chat_messages')->insertGetId(['session_id' => $sessionId, 'role' => 'assistant', 'content' => 'x', 'created_at' => $at, 'updated_at' => $at]);
        DB::table('message_traces')->insert([
            'message_id' => $id, 'trace' => json_encode(['floor_decision' => ['outcome' => $outcome, 'retrieval_score_floor' => 0.5]]),
            'created_at' => $at, 'updated_at' => $at,
        ]);

        return $id;
    }

    private function world(): void
    {
        $s = $this->newSession();
        $this->turn($s, '¿Vacaciones?', 'answer');
        $this->turn($s, '¿Mi nómina?', 'escalate');
        $this->turn($s, 'Capital de Australia', 'decline');
        $this->turn($s, 'capital de australia ', 'decline', $this->day->copy()->addHours(2));
        $this->turn($s, 'Una peli', 'decline', $this->day->copy()->addDay());
    }

    public function test_declined_is_its_own_figure_and_is_excluded_from_the_deflection_denominator(): void
    {
        $this->world();
        $a = app(DeflectionAnalytics::class);
        $summary = $a->summarize($a->liveTurns($this->day->copy()->startOfDay(), $this->day->copy()->addDays(2), []));

        $this->assertSame(1, $summary['answered']);
        $this->assertSame(1, $summary['escalated']);
        $this->assertSame(3, $summary['declined']);
        $this->assertSame(0.5, $summary['deflection_rate'], '1/(1+1): declines are neither an answer nor an escalation');
    }

    public function test_rollup_and_live_agree_on_declined_and_on_declines_per_day(): void
    {
        $this->world();
        $a = app(DeflectionAnalytics::class);
        $from = $this->day->copy()->startOfDay();
        $to = $this->day->copy()->addDays(2)->startOfDay();

        foreach ([$from, $from->copy()->addDay()] as $d) {
            DB::table('analytics_daily_rollups')->insert($a->rollupRowsForDate($d));
        }

        $live = $a->summarize($a->liveTurns($from, $to, []));
        $rolled = $a->fromRollup($from, $to, []);

        $this->assertSame($live['declined'], $rolled['declined']);
        $this->assertSame($live['deflection_rate'], $rolled['deflection_rate']);
        $this->assertSame($a->declinedByDay($from, $to, [], true), $a->declinedByDay($from, $to, [], false));
        $this->assertSame([['date' => '2026-09-01', 'declined' => 2], ['date' => '2026-09-02', 'declined' => 1]], $a->declinedByDay($from, $to, [], true));
    }

    public function test_declined_ranking_groups_by_question_and_lists_the_most_frequent_first(): void
    {
        $this->world();
        $rank = app(QuestionClusteringService::class)->declinedRanking($this->day->copy()->subDay(), $this->day->copy()->addDays(3));

        $this->assertCount(2, $rank);
        $this->assertSame(2, $rank[0]['declined_count']);
        $this->assertSame('capital de australia', mb_strtolower(trim($rank[0]['medoid_text'])));
        $this->assertSame(1, $rank[1]['declined_count']);
        $this->assertNull($rank[0]['cluster_id']);
    }

    public function test_the_analytics_endpoints_return_the_new_figures(): void
    {
        $this->world();
        $admin = Admin::create(['email' => 'rep-admin@example.com', 'full_name' => 'Admin', 'status' => 'active']);
        $admin->assignRole('super_admin');
        $h = ['Authorization' => 'Bearer '.$admin->createToken('t')->plainTextToken, 'Accept' => 'application/json'];

        $d = $this->getJson('/admin/analytics/deflection?live=1&from=2026-09-01&to=2026-09-02', $h);
        $d->assertOk();
        $this->assertSame(3, $d->json('summary.declined'));
        $this->assertCount(2, $d->json('declined_by_day'));

        $c = $this->getJson('/admin/analytics/clusters', $h);
        $c->assertOk();
        $this->assertIsArray($c->json('declined_ranking'));
    }

    public function test_historial_flags_a_decline_only_session_and_filters_by_declined(): void
    {
        $declinedOnly = $this->newSession();
        $this->turn($declinedOnly, 'Capital de Australia', 'decline');
        $mixed = $this->newSession();
        $this->turn($mixed, '¿Vacaciones?', 'answer');
        $this->turn($mixed, 'Una peli', 'decline');
        $plain = $this->newSession();
        $this->turn($plain, '¿Vacaciones?', 'answer');

        $admin = Admin::create(['email' => 'hist-admin@example.com', 'full_name' => 'Admin', 'status' => 'active']);
        $admin->assignRole('super_admin');
        $h = ['Authorization' => 'Bearer '.$admin->createToken('t')->plainTextToken, 'Accept' => 'application/json'];

        $all = collect($this->getJson('/admin/history/conversations', $h)->assertOk()->json('data'));
        $uuid = fn (int $id) => DB::table('chat_sessions')->where('id', $id)->value('uuid');
        $row = fn (int $id) => $all->firstWhere('session_uuid', $uuid($id));

        $this->assertTrue($row($declinedOnly)['declined']);
        $this->assertTrue($row($declinedOnly)['declined_only'], 'a session whose every turn was declined was never "answered"');
        $this->assertTrue($row($mixed)['declined']);
        $this->assertFalse($row($mixed)['declined_only']);
        $this->assertFalse($row($plain)['declined']);
        $this->assertFalse($row($plain)['declined_only']);

        $only = collect($this->getJson('/admin/history/conversations?outcome=declined', $h)->assertOk()->json('data'))->pluck('session_uuid')->all();
        $this->assertEqualsCanonicalizing([$uuid($declinedOnly), $uuid($mixed)], $only);
    }
}
