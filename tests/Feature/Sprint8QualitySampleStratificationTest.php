<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Convenio;
use App\Models\Employee;
use App\Models\QualitySample;
use App\Models\Sector;
use App\Models\Territory;
use App\Support\QualitySamplingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Sprint 8, Step 6 (plan.md §6.2, §6.3, §6.4, §11) — proves:
 * - same month + same seed => same drawn `message_id` set (reproducibility).
 * - the per-stratum floor (`max(1, …)`) is respected, so a rare stratum
 *   (few turns of one path/territory) is never sampled down to zero.
 * - `recordVerdict('wrong', …)` opens an escalation card via the SAME
 *   `EscalationExplainer` machinery every other reason uses (no parallel path).
 * - §6.3's "reviewer ≠ the agent who handled any related card" guard.
 */
class Sprint8QualitySampleStratificationTest extends TestCase
{
    use RefreshDatabase;

    private Territory $territoryA;

    private Territory $territoryB;

    private Employee $employeeA;

    private Employee $employeeB;

    private Carbon $day;

    protected function setUp(): void
    {
        parent::setUp();
        $this->day = Carbon::create(2026, 9, 5, 10, 0, 0);
        $this->buildWorld();
    }

    private function buildWorld(): void
    {
        $this->territoryA = Territory::create(['code' => '01', 'name' => 'Álava', 'level' => 'provincial', 'aliases' => []]);
        $this->territoryB = Territory::create(['code' => '02', 'name' => 'Albacete', 'level' => 'provincial', 'aliases' => []]);
        $sector = Sector::create(['name' => 'Test Sector', 'aliases' => []]);
        $convenioA = Convenio::create(['numero' => '01TESTQ001', 'name' => 'Convenio A', 'territory_id' => $this->territoryA->id, 'sector_id' => $sector->id]);
        $convenioB = Convenio::create(['numero' => '02TESTQ001', 'name' => 'Convenio B', 'territory_id' => $this->territoryB->id, 'sector_id' => $sector->id]);

        $this->employeeA = Employee::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(), 'email' => 'qa@example.com', 'full_name' => 'Quality Asker A',
            'convenio_id' => $convenioA->id, 'territory_id' => $this->territoryA->id, 'employment_type' => 'full_time', 'status' => 'active',
        ]);
        $this->employeeB = Employee::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(), 'email' => 'qb@example.com', 'full_name' => 'Quality Asker B',
            'convenio_id' => $convenioB->id, 'territory_id' => $this->territoryB->id, 'employment_type' => 'full_time', 'status' => 'active',
        ]);

        $sessionA = DB::table('chat_sessions')->insertGetId(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'employee_id' => $this->employeeA->id, 'started_at' => $this->day, 'created_at' => $this->day, 'updated_at' => $this->day]);
        $sessionB = DB::table('chat_sessions')->insertGetId(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'employee_id' => $this->employeeB->id, 'started_at' => $this->day, 'created_at' => $this->day, 'updated_at' => $this->day]);

        // Stratum (prose, territory A): 5 answered turns — the common path.
        for ($i = 1; $i <= 5; $i++) {
            $this->makeAnsweredTurn($sessionA, "prose q{$i} from A", ['floor_decision' => ['outcome' => 'answer', 'retrieval_score_floor' => 0.9]]);
        }
        // Stratum (reference_fact, territory B): 1 answered turn — the RARE
        // stratum the max(1, …) floor exists for.
        $this->makeAnsweredTurn($sessionB, 'fact q1 from B', ['floor_decision' => ['outcome' => 'answer', 'path' => 'reference_fact']]);
    }

    private function makeAnsweredTurn(int $sessionId, string $content, array $trace): int
    {
        DB::table('chat_messages')->insert([
            'session_id' => $sessionId, 'role' => 'user', 'content' => $content,
            'created_at' => $this->day, 'updated_at' => $this->day,
        ]);
        $messageId = DB::table('chat_messages')->insertGetId([
            'session_id' => $sessionId, 'role' => 'assistant', 'content' => 'answer to: '.$content,
            'created_at' => $this->day, 'updated_at' => $this->day,
        ]);
        DB::table('message_traces')->insert([
            'message_id' => $messageId, 'trace' => json_encode($trace),
            'created_at' => $this->day, 'updated_at' => $this->day,
        ]);

        return $messageId;
    }

    public function test_same_month_and_seed_reproduces_the_identical_sample(): void
    {
        $service = app(QualitySamplingService::class);

        $first = $service->draw('2026-09', 3, 12345);
        $idsFirst = QualitySample::where('sampled_for_month', '2026-09')->pluck('message_id')->sort()->values()->all();

        $second = $service->draw('2026-09', 3, 12345);
        $idsSecond = QualitySample::where('sampled_for_month', '2026-09')->pluck('message_id')->sort()->values()->all();

        $this->assertSame($idsFirst, $idsSecond);
        $this->assertSame($first['drawn'], $second['drawn']);
    }

    public function test_rare_stratum_is_never_sampled_down_to_zero(): void
    {
        $service = app(QualitySamplingService::class);

        // n=1 is deliberately smaller than the strata count (2) — pure
        // proportional allocation would round the rare stratum to 0.
        $service->draw('2026-09', 1, 999);

        $rareStratumCount = QualitySample::where('sampled_for_month', '2026-09')
            ->where('stratum_path', 'reference_fact')
            ->where('stratum_territory_id', $this->territoryB->id)
            ->count();

        $this->assertSame(1, $rareStratumCount, 'the max(1, …) floor must guarantee the rare stratum is represented');
    }

    public function test_default_seed_is_deterministic_from_the_month_string(): void
    {
        $service = app(QualitySamplingService::class);
        $result = $service->draw('2026-09', 3);

        $this->assertSame((int) crc32('2026-09'), $result['seed']);
    }

    public function test_rerun_preserves_already_reviewed_rows(): void
    {
        $service = app(QualitySamplingService::class);
        $admin = Admin::create(['full_name' => 'QA Reviewer', 'email' => 'qa-reviewer@example.com', 'status' => 'active']);

        $service->draw('2026-09', 3, 12345);
        $reviewed = QualitySample::where('sampled_for_month', '2026-09')->first();
        $service->recordVerdict($reviewed, $admin, 'correct', null, null);

        // Re-running the draw for the same month must not touch the
        // already-reviewed row (only unreviewed rows are replaced).
        $service->draw('2026-09', 3, 12345);

        $stillThere = QualitySample::find($reviewed->id);
        $this->assertNotNull($stillThere);
        $this->assertSame('correct', $stillThere->verdict);
        $this->assertSame($admin->id, $stillThere->reviewed_by);
    }

    public function test_verdict_wrong_opens_a_fix_card_via_escalation_explainer(): void
    {
        $service = app(QualitySamplingService::class);
        $admin = Admin::create(['full_name' => 'QA Reviewer', 'email' => 'qa-reviewer2@example.com', 'status' => 'active']);

        $service->draw('2026-09', 3, 12345);
        $sample = QualitySample::where('sampled_for_month', '2026-09')->first();

        $updated = $service->recordVerdict($sample, $admin, 'wrong', 'stale_document', 'the convenio version cited is outdated');

        $this->assertNotNull($updated->escalation_card_id);
        $card = $updated->escalationCard;
        $this->assertSame('quality_sample_wrong', $card->reason);
        $this->assertSame('stale_document', $card->explanation_facts['sub_outcome']);
        $this->assertSame('Documentos', $card->fix_surface);
        $this->assertSame('#view=documents', $card->fix_link);
    }

    public function test_verdict_correct_never_opens_a_card(): void
    {
        $service = app(QualitySamplingService::class);
        $admin = Admin::create(['full_name' => 'QA Reviewer', 'email' => 'qa-reviewer3@example.com', 'status' => 'active']);

        $service->draw('2026-09', 3, 12345);
        $sample = QualitySample::where('sampled_for_month', '2026-09')->first();

        $updated = $service->recordVerdict($sample, $admin, 'correct', null, null);

        $this->assertNull($updated->escalation_card_id);
        $this->assertNull($updated->failure_kind);
    }

    public function test_reviewer_assigned_to_a_related_card_is_barred(): void
    {
        $service = app(QualitySamplingService::class);
        $barredAdmin = Admin::create(['full_name' => 'Barred Agent', 'email' => 'barred@example.com', 'status' => 'active']);

        $service->draw('2026-09', 3, 12345);
        $sample = QualitySample::where('sampled_for_month', '2026-09')->first();

        DB::table('escalation_cards')->insert([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'chat_session_id' => $sample->message->session_id,
            'employee_id' => $this->employeeA->id,
            'reason' => 'low_confidence',
            'status' => 'assigned',
            'assigned_to' => $barredAdmin->id,
            'created_at' => $this->day, 'updated_at' => $this->day,
        ]);

        $this->assertTrue($service->reviewerIsBarred($sample, $barredAdmin));
        $this->expectException(\RuntimeException::class);
        $service->recordVerdict($sample, $barredAdmin, 'correct', null, null);
    }

    /**
     * Calidad eyes-on round 2 (2026-09-11) — the PREGUNTA column was showing
     * the ASSISTANT's answer, because `message_id` always points at the
     * assistant turn (§6.1). `pairedUserMessage()` must resolve the actual
     * employee question, not the answer.
     */
    public function test_paired_user_message_resolves_the_employee_question_not_the_answer(): void
    {
        $service = app(QualitySamplingService::class);

        $service->draw('2026-09', 3, 12345);
        $sample = QualitySample::where('sampled_for_month', '2026-09')
            ->where('stratum_path', 'reference_fact')->first();

        $question = $service->pairedUserMessage($sample);

        $this->assertNotNull($question);
        $this->assertSame('user', $question->role);
        $this->assertSame('fact q1 from B', $question->content);
        $this->assertNotSame($sample->message->content, $question->content);
    }

    /**
     * Calidad eyes-on round 2 (2026-09-11) — §6.5's monthly summary/trend
     * requirement: the trend endpoint's grouped rows, aggregated by
     * (month, verdict) exactly as the frontend's monthly summary line does,
     * must sum to the real recorded verdict counts.
     */
    public function test_monthly_trend_sums_to_the_real_verdict_counts(): void
    {
        $service = app(QualitySamplingService::class);
        $admin = Admin::create(['full_name' => 'QA Trend Reviewer', 'email' => 'qa-trend@example.com', 'status' => 'active']);

        $service->draw('2026-09', 6, 12345);
        $samples = QualitySample::where('sampled_for_month', '2026-09')->orderBy('id')->get();
        $this->assertCount(6, $samples, 'the fixture draws all 6 answered turns (5 + 1) at n=6');

        // A known, mixed set: 4 correct, 1 partially, 1 wrong.
        $verdicts = ['correct', 'correct', 'correct', 'correct', 'partially', 'wrong'];
        foreach ($samples as $i => $sample) {
            $verdict = $verdicts[$i];
            $service->recordVerdict($sample, $admin, $verdict, $verdict === 'correct' ? null : 'unclear', null);
        }

        $byMonthVerdict = [];
        foreach ($service->monthlyTrend() as $row) {
            if ($row['verdict'] === null) {
                continue;
            }
            $byMonthVerdict[$row['month']][$row['verdict']] = ($byMonthVerdict[$row['month']][$row['verdict']] ?? 0) + $row['count'];
        }

        $this->assertSame(4, $byMonthVerdict['2026-09']['correct']);
        $this->assertSame(1, $byMonthVerdict['2026-09']['partially']);
        $this->assertSame(1, $byMonthVerdict['2026-09']['wrong']);
    }
}
