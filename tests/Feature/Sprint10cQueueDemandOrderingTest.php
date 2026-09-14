<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Convenio;
use App\Models\ReferenceFact;
use App\Models\Sector;
use App\Models\Territory;
use App\Models\Topic;
use Database\Seeders\DocumentTypeSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Sprint 10c, D7 — the reference-facts queue's THIRD ordering tier
 * (`topic_demand_score`, written nightly by `questions:cluster` into
 * `topic_demand_scores`). Presentation-only: proves the tier is applied
 * strictly AFTER uncertainty and confidence (safety outranks demand —
 * D7's own wording), that it is safely additive when no
 * `topic_demand_scores` rows exist at all (fresh install / before the first
 * nightly run), and that the existing `topic_id` filter still works
 * correctly once `queue=true` also left-joins a table that HAS its own
 * `topic_id` column (the ambiguous-column regression this sprint's fix
 * guards against).
 */
class Sprint10cQueueDemandOrderingTest extends TestCase
{
    use RefreshDatabase;

    private Admin $admin;

    private Convenio $convenio;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(DocumentTypeSeeder::class);

        $this->admin = Admin::create(['email' => 'kc10c-d7@example.com', 'full_name' => 'KC 10c D7', 'status' => 'active']);
        $this->admin->assignRole('super_admin');

        $territory = Territory::create(['code' => '10c', 'name' => 'Aragón 10c-D7', 'level' => 'provincial', 'aliases' => []]);
        $sector = Sector::create(['name' => 'Sector 10c-D7', 'aliases' => []]);
        $this->convenio = Convenio::create([
            'numero' => '10777715012099', 'name' => 'Convenio 10c-D7',
            'territory_id' => $territory->id, 'sector_id' => $sector->id,
        ]);
    }

    /** @return array<string,string> */
    private function auth(): array
    {
        $this->app['auth']->forgetGuards();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return ['Authorization' => 'Bearer '.$this->admin->createToken('t')->plainTextToken, 'Accept' => 'application/json'];
    }

    private function makeFact(Topic $topic, ?float $confidence, ?array $uncertainty, string $value): ReferenceFact
    {
        return ReferenceFact::create([
            'convenio_id' => $this->convenio->id, 'topic_id' => $topic->id,
            'value' => $value, 'authority_level' => ReferenceFact::AUTHORITY_LEVEL,
            'source' => 'ai_agent', 'status' => 'needs_review',
            'confidence' => $confidence, 'uncertainty' => $uncertainty,
        ]);
    }

    public function test_demand_score_breaks_ties_within_the_same_uncertainty_and_confidence_tier(): void
    {
        $lowDemandTopic = Topic::firstOrCreate(['name' => 'jornada 10c-d7'], ['status' => 'approved']);
        $highDemandTopic = Topic::firstOrCreate(['name' => 'vacaciones 10c-d7'], ['status' => 'approved']);

        // Same confidence, no uncertainty flag on either — same tier, so the
        // demand score is the ONLY thing that should decide their order.
        $lowDemandFact = $this->makeFact($lowDemandTopic, 0.9, null, 'low demand fact');
        $highDemandFact = $this->makeFact($highDemandTopic, 0.9, null, 'high demand fact');

        DB::table('topic_demand_scores')->insert([
            ['run_date' => now()->toDateString(), 'topic_key' => 'jornada', 'topic_id' => $lowDemandTopic->id, 'volume' => 1, 'score' => 5.0, 'headcount_weight' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['run_date' => now()->toDateString(), 'topic_key' => 'vacaciones', 'topic_id' => $highDemandTopic->id, 'volume' => 10, 'score' => 500.0, 'headcount_weight' => 50, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $res = $this->getJson('/admin/reference-facts?queue=true', $this->auth())->assertStatus(200);
        $ids = collect($res->json('facts.data'))->pluck('id')->all();

        $this->assertSame(
            [$highDemandFact->id, $lowDemandFact->id],
            array_values(array_intersect($ids, [$highDemandFact->id, $lowDemandFact->id])),
            'the higher-demand-topic fact should sort before the lower-demand one when uncertainty/confidence tie',
        );
    }

    public function test_uncertainty_still_outranks_demand_safety_before_demand(): void
    {
        $topic = Topic::firstOrCreate(['name' => 'festivos 10c-d7'], ['status' => 'approved']);

        // The flagged fact's TOPIC has a much LOWER demand score than the
        // unflagged one's — if demand ever outranked uncertainty, the
        // unflagged/high-demand fact would sort first. It must not.
        $flaggedLowDemandTopic = Topic::firstOrCreate(['name' => 'excedencias 10c-d7'], ['status' => 'approved']);
        $flagged = $this->makeFact($flaggedLowDemandTopic, 0.95, ['field' => 'scope', 'reason' => 'ambiguo'], 'flagged, low demand');
        $unflaggedHighDemand = $this->makeFact($topic, 0.4, null, 'unflagged, high demand');

        DB::table('topic_demand_scores')->insert([
            ['run_date' => now()->toDateString(), 'topic_key' => 'excedencia', 'topic_id' => $flaggedLowDemandTopic->id, 'volume' => 1, 'score' => 1.0, 'headcount_weight' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['run_date' => now()->toDateString(), 'topic_key' => 'festivos', 'topic_id' => $topic->id, 'volume' => 20, 'score' => 900.0, 'headcount_weight' => 90, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $res = $this->getJson('/admin/reference-facts?queue=true', $this->auth())->assertStatus(200);
        $ids = collect($res->json('facts.data'))->pluck('id')->all();

        $this->assertSame(
            [$flagged->id, $unflaggedHighDemand->id],
            array_values(array_intersect($ids, [$flagged->id, $unflaggedHighDemand->id])),
            'an uncertainty-flagged fact must sort before an unflagged one regardless of topic demand',
        );
    }

    public function test_queue_is_unaffected_when_no_demand_scores_have_ever_been_written(): void
    {
        // Fresh-install case: `topic_demand_scores` is empty (no
        // `questions:cluster` run has ever executed). The left join must be
        // a pure no-op — same behaviour as before this sprint.
        $topic = Topic::firstOrCreate(['name' => 'formación 10c-d7'], ['status' => 'approved']);
        $fact = $this->makeFact($topic, 0.5, null, 'no demand data yet');

        $this->assertSame(0, DB::table('topic_demand_scores')->count());

        $res = $this->getJson('/admin/reference-facts?queue=true', $this->auth())->assertStatus(200);
        $res->assertJsonPath('facts.data.0.id', $fact->id);
    }

    public function test_topic_id_filter_still_works_combined_with_queue_true(): void
    {
        // The regression this sprint's fix guards against: `topic_demand_scores`
        // ALSO has a `topic_id` column. Before qualifying the filter's WHERE
        // clause with the table name, `?topic_id=&queue=true` together would
        // raise a Postgres ambiguous-column error the moment both a topic_id
        // filter AND the queue's left join were present in the same query.
        $topicA = Topic::firstOrCreate(['name' => 'conciliación 10c-d7'], ['status' => 'approved']);
        $topicB = Topic::firstOrCreate(['name' => 'retribución 10c-d7'], ['status' => 'approved']);
        $factA = $this->makeFact($topicA, 0.5, null, 'topic A fact');
        $this->makeFact($topicB, 0.5, null, 'topic B fact');

        $res = $this->getJson("/admin/reference-facts?queue=true&topic_id={$topicA->id}", $this->auth())
            ->assertStatus(200);

        $res->assertJsonCount(1, 'facts.data')
            ->assertJsonPath('facts.data.0.id', $factA->id);
    }
}
