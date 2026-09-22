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
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Correction queue-source-01 — found live: fact #169 (an `admin_manual`
 * convenio-wide create, Sprint 7b-1's own path) had no way to be reached from
 * the Reference-facts review tab at all. `store()` lands a manual fact
 * `needs_review` exactly like the segmentation agent does, but
 * `ReferenceFactController::index()`'s `queue=true` branch additionally
 * required `source = 'ai_agent'`, so a manual fact sat inert with no UI path
 * to verify it — a queue-shape bug, not a status bug (§ found via a
 * read-only check reported separately before this fix).
 *
 * Fix: the queue lists every `needs_review` fact regardless of `source`.
 * Ordering (uncertainty → confidence → topic demand) is UNCHANGED — a manual
 * fact simply has no `confidence`/`uncertainty` to rank by (only the
 * segmentation agent ever writes those columns), so it falls to the bottom
 * of its tier under the same rules, never exempted from them.
 */
class Sprint10cCorrectionQueueSource01Test extends TestCase
{
    use RefreshDatabase;

    private Admin $admin;

    private Convenio $convenio;

    private Topic $topic;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(DocumentTypeSeeder::class);

        $this->admin = Admin::create(['email' => 'kc-qs01@example.com', 'full_name' => 'KC Queue-Source-01', 'status' => 'active']);
        $this->admin->assignRole('super_admin');

        $territory = Territory::create(['code' => 'qs01', 'name' => 'Aragón QS01', 'level' => 'provincial', 'aliases' => []]);
        $sector = Sector::create(['name' => 'Sector QS01', 'aliases' => []]);
        $this->convenio = Convenio::create([
            'numero' => '10777715012098', 'name' => 'Convenio QS01',
            'territory_id' => $territory->id, 'sector_id' => $sector->id,
        ]);
        $this->topic = Topic::firstOrCreate(['name' => 'vacaciones qs01'], ['status' => 'approved']);
    }

    /** @return array<string,string> */
    private function auth(): array
    {
        $this->app['auth']->forgetGuards();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return ['Authorization' => 'Bearer '.$this->admin->createToken('t')->plainTextToken, 'Accept' => 'application/json'];
    }

    private function makeFact(string $source, string $status, string $value, ?float $confidence = null): ReferenceFact
    {
        return ReferenceFact::create([
            'convenio_id' => $this->convenio->id, 'topic_id' => $this->topic->id,
            'value' => $value, 'authority_level' => ReferenceFact::AUTHORITY_LEVEL,
            'source' => $source, 'status' => $status, 'confidence' => $confidence,
        ]);
    }

    public function test_manual_needs_review_fact_appears_in_the_queue_and_can_be_verified_through_the_normal_action(): void
    {
        $manual = $this->makeFact('admin_manual', 'needs_review', 'todas las personas trabajadoras — un mes de vacaciones');

        $res = $this->getJson('/admin/reference-facts?queue=true', $this->auth())->assertStatus(200);
        $ids = collect($res->json('facts.data'))->pluck('id')->all();
        $this->assertContains($manual->id, $ids, 'a manual needs_review fact must appear in the queue (fact #169\'s bug)');

        $row = collect($res->json('facts.data'))->firstWhere('id', $manual->id);
        $this->assertTrue($row['is_manual_pending'], 'the row carries the neutral "Manual" badge condition');
        $this->assertFalse($row['is_ai_proposed'], 'a manual fact must never carry the fuchsia AI-proposal condition');

        // The SAME action an AI proposal uses — no separate manual verify path.
        $this->postJson("/admin/reference-facts/{$manual->uuid}/verify", [], $this->auth())
            ->assertStatus(200)
            ->assertJsonPath('fact_status', 'verified');

        $this->assertSame('verified', $manual->refresh()->status);
        $this->assertSame($this->admin->id, $manual->verified_by);
    }

    public function test_verified_fact_of_either_source_does_not_appear_in_the_queue(): void
    {
        $verifiedAi = $this->makeFact('ai_agent', 'verified', 'ai fact, already verified');
        $verifiedManual = $this->makeFact('admin_manual', 'verified', 'manual fact, already verified');
        $pendingAi = $this->makeFact('ai_agent', 'needs_review', 'ai fact, still pending');
        $pendingManual = $this->makeFact('admin_manual', 'needs_review', 'manual fact, still pending');

        $res = $this->getJson('/admin/reference-facts?queue=true', $this->auth())->assertStatus(200);
        $ids = collect($res->json('facts.data'))->pluck('id')->all();

        $this->assertNotContains($verifiedAi->id, $ids, 'a verified AI-proposed fact must not reappear in the queue');
        $this->assertNotContains($verifiedManual->id, $ids, 'a verified manual fact must not appear in the queue');
        $this->assertContains($pendingAi->id, $ids);
        $this->assertContains($pendingManual->id, $ids);
    }

    public function test_manual_fact_with_no_confidence_falls_to_the_bottom_of_its_tier_not_exempted_from_ordering(): void
    {
        // Same tier (no uncertainty on either) — the AI fact has a real
        // confidence score; the manual fact has none (only the segmentation
        // agent ever writes `confidence`). `confidence ASC NULLS LAST` must
        // still put the manual fact last, exactly as it would a low-signal
        // AI fact — the manual source is not a special case in the ORDER BY.
        $aiFact = $this->makeFact('ai_agent', 'needs_review', 'ai fact with confidence', 0.5);
        $manualFact = $this->makeFact('admin_manual', 'needs_review', 'manual fact, no confidence at all');

        $res = $this->getJson('/admin/reference-facts?queue=true', $this->auth())->assertStatus(200);
        $ids = collect($res->json('facts.data'))->pluck('id')->all();

        $this->assertSame(
            [$aiFact->id, $manualFact->id],
            array_values(array_intersect($ids, [$aiFact->id, $manualFact->id])),
            'a manual fact (NULL confidence) sorts after a scored AI fact in the same uncertainty tier',
        );
    }
}
