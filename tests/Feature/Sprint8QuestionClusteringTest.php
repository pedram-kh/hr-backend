<?php

namespace Tests\Feature;

use App\Models\Convenio;
use App\Models\Employee;
use App\Models\Sector;
use App\Models\Territory;
use App\Models\Topic;
use App\Services\ExtractionClient;
use App\Support\QuestionClusteringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Sprint 8, Step 5 (plan.md §4.2) — proves the greedy single-link clustering,
 * medoid labelling (no LLM), and the escalation-rate/headcount-weight ranking
 * helper, hermetically: a SCRIPTED embed client (same precedent as
 * `Sprint7dCalibrationTest`'s `ScriptedCompareClient`) returns fixed,
 * hand-designed vectors, so the test is deterministic and independent of a
 * live hr-ai/model process.
 */
class Sprint8QuestionClusteringTest extends TestCase
{
    use RefreshDatabase;

    private Employee $employeeA;

    private Employee $employeeB;

    private Carbon $day;

    protected function setUp(): void
    {
        parent::setUp();
        $this->day = Carbon::create(2026, 9, 1, 9, 0, 0);
        $this->buildWorld();
    }

    private function buildWorld(): void
    {
        $territory = Territory::create(['code' => '01', 'name' => 'Álava', 'level' => 'provincial', 'aliases' => []]);
        $sector = Sector::create(['name' => 'Test Sector', 'aliases' => []]);
        $convenio = Convenio::create(['numero' => '01TESTC001', 'name' => 'Test Convenio', 'territory_id' => $territory->id, 'sector_id' => $sector->id]);
        $this->employeeA = Employee::create([
            'uuid' => (string) Str::uuid(), 'email' => 'ca@example.com', 'full_name' => 'Cluster Asker A',
            'convenio_id' => $convenio->id, 'territory_id' => $territory->id, 'employment_type' => 'full_time', 'status' => 'active',
        ]);
        $this->employeeB = Employee::create([
            'uuid' => (string) Str::uuid(), 'email' => 'cb@example.com', 'full_name' => 'Cluster Asker B',
            'convenio_id' => $convenio->id, 'territory_id' => $territory->id, 'employment_type' => 'full_time', 'status' => 'active',
        ]);

        $sessionA = DB::table('chat_sessions')->insertGetId(['uuid' => (string) Str::uuid(), 'employee_id' => $this->employeeA->id, 'started_at' => $this->day, 'created_at' => $this->day, 'updated_at' => $this->day]);
        $sessionB = DB::table('chat_sessions')->insertGetId(['uuid' => (string) Str::uuid(), 'employee_id' => $this->employeeB->id, 'started_at' => $this->day, 'created_at' => $this->day, 'updated_at' => $this->day]);

        // Two near-duplicate vacation questions (should cluster together) +
        // one unrelated question (its own cluster).
        $q1 = DB::table('chat_messages')->insertGetId(['session_id' => $sessionA, 'role' => 'user', 'content' => '¿cuántos días de vacaciones tengo?', 'created_at' => $this->day, 'updated_at' => $this->day]);
        $q2 = DB::table('chat_messages')->insertGetId(['session_id' => $sessionB, 'role' => 'user', 'content' => 'vacaciones que me corresponden', 'created_at' => $this->day->copy()->addHour(), 'updated_at' => $this->day]);
        $q3 = DB::table('chat_messages')->insertGetId(['session_id' => $sessionA, 'role' => 'user', 'content' => '¿cuál es mi salario?', 'created_at' => $this->day->copy()->addHours(2), 'updated_at' => $this->day]);

        // q1 escalated (no answer given); q2/q3 not.
        DB::table('escalation_cards')->insert([
            'uuid' => (string) Str::uuid(), 'employee_id' => $this->employeeA->id,
            'source_message_id' => $q1, 'reason' => 'low_confidence', 'status' => 'new',
            'created_at' => $this->day, 'updated_at' => $this->day,
        ]);
    }

    public function test_near_duplicates_cluster_together_and_medoid_is_not_llm_generated(): void
    {
        $scripted = new ScriptedEmbedClient([
            '¿cuántos días de vacaciones tengo?' => [1.0, 0.0, 0.0],
            'vacaciones que me corresponden' => [0.95, 0.312, 0.0], // cosine ≈ 0.95 with q1
            '¿cuál es mi salario?' => [0.0, 0.0, 1.0], // orthogonal — its own cluster
        ]);
        $this->app->instance(ExtractionClient::class, $scripted);

        $service = app(QuestionClusteringService::class);
        $result = $service->run($this->day->copy()->subDay(), $this->day->copy()->addDay(), $this->day, 0.80);

        $this->assertSame(2, $result['clusters']);
        $this->assertSame(3, $result['distinct_texts']);
        $this->assertSame(3, $result['members']);

        $clusters = DB::table('question_clusters')->orderByDesc('distinct_text_count')->get();
        $this->assertCount(2, $clusters);

        $vacationCluster = $clusters->firstWhere('distinct_text_count', 2);
        $this->assertNotNull($vacationCluster);
        // The medoid MUST be one of the two actual member texts — never a
        // synthesized/LLM-generated summary string (the hard constraint).
        $this->assertContains($vacationCluster->medoid_text, [
            '¿cuántos días de vacaciones tengo?',
            'vacaciones que me corresponden',
        ]);
        $this->assertEqualsWithDelta(0.95, $vacationCluster->min_similarity, 0.01);
        $this->assertEqualsWithDelta(0.95, $vacationCluster->max_similarity, 0.01);
        $this->assertSame(2, $vacationCluster->member_count); // 2 turns (q1 + q2), both distinct texts

        $salaryCluster = $clusters->firstWhere('distinct_text_count', 1);
        $this->assertSame('¿cuál es mi salario?', $salaryCluster->medoid_text);
        $this->assertNull($salaryCluster->min_similarity);
    }

    public function test_escalation_rate_and_headcount_weight(): void
    {
        $scripted = new ScriptedEmbedClient([
            '¿cuántos días de vacaciones tengo?' => [1.0, 0.0, 0.0],
            'vacaciones que me corresponden' => [0.95, 0.312, 0.0],
            '¿cuál es mi salario?' => [0.0, 0.0, 1.0],
        ]);
        $this->app->instance(ExtractionClient::class, $scripted);

        app(QuestionClusteringService::class)->run($this->day->copy()->subDay(), $this->day->copy()->addDay(), $this->day, 0.80);

        $vacationCluster = DB::table('question_clusters')->where('distinct_text_count', 2)->first();
        // 1 of 2 members (q1) was escalated -> 0.5.
        $this->assertSame(0.5, $vacationCluster->escalation_rate);
        $this->assertSame('low_confidence', $vacationCluster->top_escalation_reason);
        // 2 distinct askers (employeeA, employeeB), both on the same convenio
        // with headcount 2 (both active) -> weight = 2 (headcount) not 2x2,
        // since headcount is per-convenio, summed once per distinct asker's
        // convenio -- both askers share convenio_id, so this sums the SAME
        // convenio headcount twice by design (one per asker), i.e. 2 + 2 = 4.
        $this->assertSame(4, $vacationCluster->headcount_weight);

        $salaryCluster = DB::table('question_clusters')->where('distinct_text_count', 1)->first();
        $this->assertSame(0.0, $salaryCluster->escalation_rate);
        $this->assertNull($salaryCluster->top_escalation_reason);
    }

    public function test_topic_breakdown_is_a_direct_lexicon_call_over_every_turn(): void
    {
        // No embedding call needed — §4.1 is independent of clustering.
        $service = app(QuestionClusteringService::class);

        $breakdown = $service->topicBreakdown($this->day->copy()->subDay(), $this->day->copy()->addDay());

        // 2 of the 3 fixture turns anchor to 'vacaciones'; the salary question
        // anchors to none of the 14 topic keys (untagged, not an error) --
        // so exactly one topic key is present, with count 2.
        $this->assertSame(['vacaciones' => 2], $breakdown);
    }

    public function test_unanswered_ranking_uses_the_persisted_formula_terms(): void
    {
        $scripted = new ScriptedEmbedClient([
            '¿cuántos días de vacaciones tengo?' => [1.0, 0.0, 0.0],
            'vacaciones que me corresponden' => [0.95, 0.312, 0.0],
            '¿cuál es mi salario?' => [0.0, 0.0, 1.0],
        ]);
        $this->app->instance(ExtractionClient::class, $scripted);

        app(QuestionClusteringService::class)->run($this->day->copy()->subDay(), $this->day->copy()->addDay(), $this->day, 0.80);

        $ranking = app(QuestionClusteringService::class)->unansweredRanking($this->day);

        $this->assertCount(2, $ranking);
        // vacation cluster ranks first: escalation_rate=0.5 x volume=2 x
        // headcount_weight=4 = score 4.0 (nonzero, since it was escalated).
        $this->assertContains($ranking[0]['medoid_text'], [
            '¿cuántos días de vacaciones tengo?',
            'vacaciones que me corresponden',
        ]);
        $this->assertEqualsWithDelta(4.0, $ranking[0]['score'], 0.001);
        // salary cluster ranks last: never escalated -> escalation_rate=0 -> score 0.
        $this->assertSame('¿cuál es mi salario?', $ranking[1]['medoid_text']);
        $this->assertSame(0.0, $ranking[1]['score']);
    }

    // ---- Sprint 10c, D7 — per-topic demand score (independent of clustering) --

    public function test_topic_demand_score_uses_the_same_formula_terms_aggregated_by_topic(): void
    {
        // Same fixture as the cluster tests above (q1 escalated, q2/q3 not) —
        // proving the SAME numbers fall out whether aggregated by cluster
        // membership (unansweredRanking) or by TopicLexicon topic_key
        // (computeTopicDemandScores), since both share one formula.
        $topic = Topic::firstOrCreate(['name' => 'vacaciones'], ['status' => 'approved']);

        $written = app(QuestionClusteringService::class)->computeTopicDemandScores(
            $this->day->copy()->subDay(), $this->day->copy()->addDay(), $this->day,
        );

        // Exactly one topic_key matched anything in this fixture (§4.1's own
        // finding: the salary question anchors to none of the 14 keys).
        $this->assertSame(1, $written);

        $row = DB::table('topic_demand_scores')->where('run_date', $this->day->toDateString())->first();
        $this->assertNotNull($row);
        $this->assertSame('vacaciones', $row->topic_key);
        $this->assertSame($topic->id, $row->topic_id);
        $this->assertSame(2, $row->volume); // q1 + q2, both anchor to 'vacaciones'
        $this->assertSame(0.5, $row->escalation_rate); // 1 of 2 (q1) escalated
        $this->assertSame(4, $row->headcount_weight); // same as the cluster test — 2 askers x headcount 2 each
        $this->assertEqualsWithDelta(4.0, $row->score, 0.001); // 0.5 x 2 x 4
    }

    public function test_topic_demand_score_writes_null_topic_id_for_a_topic_key_with_no_approved_row_yet(): void
    {
        // No 'vacaciones' Topic row created here — proves this NEVER mints
        // one (ADR-0011): the demand signal is recorded with topic_id=null,
        // not silently dropped and not silently auto-created.
        $this->assertSame(0, Topic::where('name', 'vacaciones')->count());

        app(QuestionClusteringService::class)->computeTopicDemandScores(
            $this->day->copy()->subDay(), $this->day->copy()->addDay(), $this->day,
        );

        $row = DB::table('topic_demand_scores')->where('topic_key', 'vacaciones')->first();
        $this->assertNotNull($row);
        $this->assertNull($row->topic_id);
    }

    public function test_topic_demand_score_rerun_for_same_run_date_is_a_clean_replace(): void
    {
        $service = app(QuestionClusteringService::class);
        $service->computeTopicDemandScores($this->day->copy()->subDay(), $this->day->copy()->addDay(), $this->day);
        $countAfterFirst = DB::table('topic_demand_scores')->count();

        $service->computeTopicDemandScores($this->day->copy()->subDay(), $this->day->copy()->addDay(), $this->day);
        $countAfterSecond = DB::table('topic_demand_scores')->count();

        $this->assertSame($countAfterFirst, $countAfterSecond);
    }

    public function test_rerun_for_same_run_date_is_a_clean_replace(): void
    {
        $scripted = new ScriptedEmbedClient([
            '¿cuántos días de vacaciones tengo?' => [1.0, 0.0, 0.0],
            'vacaciones que me corresponden' => [0.95, 0.312, 0.0],
            '¿cuál es mi salario?' => [0.0, 0.0, 1.0],
        ]);
        $this->app->instance(ExtractionClient::class, $scripted);

        $service = app(QuestionClusteringService::class);
        $service->run($this->day->copy()->subDay(), $this->day->copy()->addDay(), $this->day, 0.80);
        $countAfterFirst = DB::table('question_clusters')->count();

        $service->run($this->day->copy()->subDay(), $this->day->copy()->addDay(), $this->day, 0.80);
        $countAfterSecond = DB::table('question_clusters')->count();

        $this->assertSame($countAfterFirst, $countAfterSecond);
    }
}

/**
 * Deterministic embed client for tests (same precedent as
 * `Sprint7dCalibrationTest`'s `ScriptedCompareClient`) — no live hr-ai/model
 * call, hand-designed vectors so cosine similarity is exactly controllable.
 */
class ScriptedEmbedClient extends ExtractionClient
{
    public function __construct(private readonly array $vectorsByText) {}

    public function embedBatch(array $texts): array
    {
        return array_map(fn ($t) => $this->vectorsByText[$t] ?? [0.0, 0.0, 0.0], $texts);
    }
}
