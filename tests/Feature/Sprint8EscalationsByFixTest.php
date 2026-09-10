<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Convenio;
use App\Models\Employee;
use App\Models\Sector;
use App\Models\Territory;
use App\Support\EscalationFixAnalytics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Sprint 8, Step 4 (plan.md §3) — proves `EscalationFixAnalytics` is a pure
 * read + group-by over `escalation_cards`'s already-7g-computed columns:
 * no new taxonomy, no write path. Also proves board throughput (resolved_at
 * - created_at per agent) and the conversion rate.
 */
class Sprint8EscalationsByFixTest extends TestCase
{
    use RefreshDatabase;

    private Employee $employee;

    private Admin $agent;

    private Carbon $day;

    protected function setUp(): void
    {
        parent::setUp();
        $this->day = Carbon::create(2026, 9, 1, 10, 0, 0);
        $this->buildWorld();
    }

    private function buildWorld(): void
    {
        $territory = Territory::create(['code' => '01', 'name' => 'Álava', 'level' => 'provincial', 'aliases' => []]);
        $sector = Sector::create(['name' => 'Test Sector', 'aliases' => []]);
        $convenio = Convenio::create(['numero' => '01TESTF001', 'name' => 'Test Convenio', 'territory_id' => $territory->id, 'sector_id' => $sector->id]);
        $this->employee = Employee::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(), 'email' => 'w2@example.com', 'full_name' => 'Worker Two',
            'convenio_id' => $convenio->id, 'territory_id' => $territory->id, 'employment_type' => 'full_time', 'status' => 'active',
        ]);
        $this->agent = Admin::create(['full_name' => 'Agent One', 'email' => 'agent1@example.com', 'status' => 'active']);

        // Card 1: low_confidence.no_retrieval, resolved 2h later, converted to a document.
        $card1 = $this->makeCard('low_confidence', 'no_retrieval', 'resolved', $this->day, $this->day->copy()->addHours(2));
        DB::table('escalation_resolutions')->insert([
            'card_id' => $card1, 'resolved_by' => $this->agent->id, 'resolution_text' => 'fixed',
            'converted_to_document_id' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);

        // Card 2: low_confidence.no_retrieval (same bucket), resolved 4h later.
        $this->makeCard('low_confidence', 'no_retrieval', 'resolved', $this->day, $this->day->copy()->addHours(4));

        // Card 3: salary_coverage_gap.no_table, still new (unresolved).
        $this->makeCard('salary_coverage_gap', 'no_table', 'new', $this->day, null);

        // A fence-blocked publish event.
        DB::table('escalation_events')->insert([
            'escalation_card_id' => $card1, 'type' => 'publish_blocked', 'created_at' => $this->day,
            'detail' => json_encode(['outcome' => 'blocked', 'max_score' => 0.91, 'threshold' => 0.78]),
        ]);
    }

    private function makeCard(string $reason, string $subOutcome, string $status, Carbon $createdAt, ?Carbon $resolvedAt): int
    {
        return DB::table('escalation_cards')->insertGetId([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'employee_id' => $this->employee->id,
            'reason' => $reason,
            'status' => $status,
            'assigned_to' => $this->agent->id,
            'explanation_facts' => json_encode(['sub_outcome' => $subOutcome]),
            'fix_action' => 'do the fix',
            'fix_surface' => 'Documentos',
            'fix_link' => '#view=documents',
            'created_at' => $createdAt,
            'resolved_at' => $resolvedAt,
            'updated_at' => $resolvedAt ?? $createdAt,
        ]);
    }

    public function test_by_fix_groups_correctly(): void
    {
        $analytics = app(EscalationFixAnalytics::class);
        $rows = $analytics->byFix($this->day->copy()->subDay(), $this->day->copy()->addDay());

        $this->assertCount(2, $rows); // two distinct (reason, sub_outcome) buckets

        $lowConfidence = $rows->firstWhere('reason', 'low_confidence');
        $this->assertSame(2, $lowConfidence['card_count']);
        $this->assertSame(2, $lowConfidence['resolved_count']);
        $this->assertSame('do the fix', $lowConfidence['fix_action']);

        $salaryGap = $rows->firstWhere('reason', 'salary_coverage_gap');
        $this->assertSame(1, $salaryGap['card_count']);
        $this->assertSame(0, $salaryGap['resolved_count']);
    }

    public function test_board_throughput(): void
    {
        $analytics = app(EscalationFixAnalytics::class);
        $throughput = $analytics->boardThroughput($this->day->copy()->subDay(), $this->day->copy()->addDay());

        $this->assertSame(2, $throughput['total_resolved']);
        $this->assertSame(0, $throughput['converted_to_document']); // fixture's resolution has converted_to_document_id = null
        $this->assertSame(0.0, $throughput['conversion_rate']);
        $this->assertCount(1, $throughput['by_agent']);
        $agentRow = $throughput['by_agent'][0];
        $this->assertSame(2, $agentRow['resolved_count']);
        // avg of 2h and 4h = 3h.
        $this->assertSame(3.0, $agentRow['avg_hours_to_resolution']);
    }

    public function test_fence_outcomes_read_existing_detail(): void
    {
        $analytics = app(EscalationFixAnalytics::class);
        $fence = $analytics->fenceOutcomes($this->day->copy()->subDay(), $this->day->copy()->addDay());

        $this->assertCount(1, $fence);
        $this->assertSame('publish_blocked', $fence->first()['type']);
        $this->assertSame(0.91, $fence->first()['detail']['max_score']);
    }

    public function test_unexplained_count_excludes_cards_with_explanation_facts(): void
    {
        $analytics = app(EscalationFixAnalytics::class);
        $this->assertSame(0, $analytics->unexplainedCount());

        // A pre-7g card with null explanation_facts.
        DB::table('escalation_cards')->insert([
            'uuid' => (string) \Illuminate\Support\Str::uuid(), 'employee_id' => $this->employee->id,
            'reason' => 'low_confidence', 'status' => 'new', 'created_at' => $this->day, 'updated_at' => $this->day,
        ]);
        $this->assertSame(1, $analytics->unexplainedCount());
    }
}
