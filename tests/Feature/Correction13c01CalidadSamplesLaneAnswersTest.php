<?php

namespace Tests\Feature;

use App\Models\Convenio;
use App\Models\Employee;
use App\Models\QualitySample;
use App\Models\Sector;
use App\Models\Territory;
use App\Support\QualitySamplingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Correction-13c-01 close: a general-knowledge lane answer (`floor_decision.path = general_knowledge`,
 * `outcome = answer`, `authority_used = [general_knowledge]`) is an answered turn like any other, so the Calidad monthly
 * draw must reach it — its own `(path, territory)` stratum, never sampled down to zero by the `max(1, …)` floor, even when
 * the lane is a tiny fraction of the month. The lane's text is model knowledge (shape-checked, not truth-checked — ADR-0038
 * amendment), so this human review is its truth check.
 */
class Correction13c01CalidadSamplesLaneAnswersTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_rare_lane_answer_is_always_in_the_monthly_calidad_draw(): void
    {
        $day = Carbon::create(2026, 9, 5, 10, 0, 0);
        $territory = Territory::create(['code' => '01', 'name' => 'Álava', 'level' => 'provincial', 'aliases' => []]);
        $sector = Sector::create(['name' => 'Test Sector', 'aliases' => []]);
        $convenio = Convenio::create(['numero' => '01TESTL001', 'name' => 'Convenio L', 'territory_id' => $territory->id, 'sector_id' => $sector->id]);
        $employee = Employee::create([
            'uuid' => (string) Str::uuid(), 'email' => 'lane-q@example.com', 'full_name' => 'Lane Asker',
            'convenio_id' => $convenio->id, 'territory_id' => $territory->id, 'employment_type' => 'full_time', 'status' => 'active',
        ]);
        $session = DB::table('chat_sessions')->insertGetId([
            'uuid' => (string) Str::uuid(), 'employee_id' => $employee->id, 'started_at' => $day, 'created_at' => $day, 'updated_at' => $day,
        ]);

        $turn = function (string $q, array $trace) use ($session, $day): int {
            DB::table('chat_messages')->insert(['session_id' => $session, 'role' => 'user', 'content' => $q, 'created_at' => $day, 'updated_at' => $day]);
            $id = DB::table('chat_messages')->insertGetId(['session_id' => $session, 'role' => 'assistant', 'content' => 'a: '.$q, 'created_at' => $day, 'updated_at' => $day]);
            DB::table('message_traces')->insert(['message_id' => $id, 'trace' => json_encode($trace), 'created_at' => $day, 'updated_at' => $day]);

            return $id;
        };

        for ($i = 1; $i <= 20; $i++) {
            $turn("prose q{$i}", ['floor_decision' => ['outcome' => 'answer', 'retrieval_score_floor' => 0.9]]);
        }
        $laneId = $turn('¿Qué es un permiso PIF?', [
            'floor_decision' => ['path' => 'general_knowledge', 'outcome' => 'answer', 'authority_used' => ['general_knowledge']],
            'general_lane' => ['basis' => 'model_knowledge'],
        ]);

        // n=3 against 21 answered turns: pure proportional allocation would round the lane stratum (1/21) to zero.
        app(QualitySamplingService::class)->draw('2026-09', 3, 4242);

        $this->assertSame(
            [$laneId],
            QualitySample::where('sampled_for_month', '2026-09')->where('stratum_path', 'general_knowledge')->pluck('message_id')->all(),
            'the lane answer sits in its own stratum and is drawn'
        );
    }
}
