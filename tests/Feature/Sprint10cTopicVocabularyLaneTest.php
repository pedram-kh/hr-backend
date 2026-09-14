<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Topic;
use App\Models\VocabularyProposal;
use App\Services\VocabularyProposalService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sprint 10c, post-CP-B decision: the topic vocabulary growth lane.
 *
 * ADR-0011's principle is "the closed set grows only by deliberate human
 * action" — the GATE, not the mechanism. Rather than build a second, bespoke
 * approve/reject lane for topics, this sprint extended the existing
 * `VocabularyProposalService::FACET_MODEL` (previously territory/sector/
 * convenio only) with `topic`, reusing `vocabulary.approve` and the existing
 * proposals-queue UI unmodified. This test proves:
 *
 *   1. A topic is created, approved, through the SAME propose→approve flow
 *      sector/territory already use — writing the `status`/`proposed_by`/
 *      `approved_by` columns on `topics` for the first time via an in-app
 *      lane (previously dead columns, only ever seeded directly).
 *   2. Authorization mirrors the other facets exactly: knowledge.edit may
 *      propose; only vocabulary.approve (super_admin) may approve.
 *   3. Topics have NO alias-fold mechanism (spelling/synonym variants are
 *      TopicLexicon's job, in code, not the controlled-vocabulary table) —
 *      attempting `resolution=alias` for facet=topic is rejected with a
 *      clear, named reason, never a silent no-op or a SQL error from writing
 *      to a column the `topics` table doesn't have.
 *   4. `suggestVariant('topic', ...)` never errors even though `Topic` has
 *      no `aliases` column (the variant search degrades to name-only
 *      matching, same code path, no special-casing needed).
 */
class Sprint10cTopicVocabularyLaneTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_super_admin_propose_and_approve_creates_an_approved_topic_with_provenance(): void
    {
        $super = Admin::create(['email' => 'sa-topic@example.com', 'full_name' => 'SA', 'status' => 'active']);
        $super->assignRole('super_admin');

        $this->postJson('/admin/vocabulary-proposals', [
            'facet' => 'topic', 'value' => 'preaviso', 'approve_now' => true, 'resolution' => 'new_value',
        ], $this->auth($super))->assertStatus(201)
            ->assertJsonPath('approved.vocab_type', 'topic')
            ->assertJsonPath('approved.vocab_name', 'preaviso');

        $topic = Topic::where('name', 'preaviso')->firstOrFail();
        $this->assertSame('approved', $topic->status);
        $this->assertSame('admin', $topic->proposed_by);
        $this->assertSame($super->id, $topic->approved_by);

        $this->assertDatabaseHas('vocabulary_proposals', [
            'facet' => 'topic', 'proposed_value' => 'preaviso', 'status' => 'approved', 'resolution' => 'new_value',
        ]);
    }

    public function test_non_super_admin_can_propose_a_topic_but_not_approve_it(): void
    {
        $editor = Admin::create(['email' => 'ke-topic@example.com', 'full_name' => 'KE', 'status' => 'active']);
        $editor->assignRole('knowledge_editor');

        $this->postJson('/admin/vocabulary-proposals', [
            'facet' => 'topic', 'value' => 'descanso semanal',
        ], $this->auth($editor))->assertStatus(201)->assertJsonPath('proposal.status', 'proposed');

        $this->assertDatabaseMissing('topics', ['name' => 'descanso semanal']);

        $this->postJson('/admin/vocabulary-proposals', [
            'facet' => 'topic', 'value' => 'otro', 'approve_now' => true, 'resolution' => 'new_value',
        ], $this->auth($editor))->assertStatus(403);

        $proposal = VocabularyProposal::where('facet', 'topic')->where('proposed_value', 'descanso semanal')->firstOrFail();
        $this->postJson("/admin/vocabulary-proposals/{$proposal->id}/approve", [
            'resolution' => 'new_value',
        ], $this->auth($editor))->assertStatus(403);

        $this->assertDatabaseMissing('topics', ['name' => 'descanso semanal']);
    }

    public function test_alias_resolution_is_rejected_for_topic_facet_with_a_named_reason(): void
    {
        Topic::create(['name' => 'vacaciones', 'status' => 'approved']);
        $service = app(VocabularyProposalService::class);

        $proposal = $service->propose('topic', 'vacaciones ', ['proposed_by_source' => 'admin_manual']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no alias-fold mechanism');
        $service->approve($proposal, 'alias', ['target_id' => Topic::where('name', 'vacaciones')->value('id')]);
    }

    public function test_alias_resolution_rejected_via_http_returns_422_not_a_500(): void
    {
        $super = Admin::create(['email' => 'sa-topic2@example.com', 'full_name' => 'SA2', 'status' => 'active']);
        $super->assignRole('super_admin');
        $existing = Topic::create(['name' => 'jornada', 'status' => 'approved']);

        $this->postJson('/admin/vocabulary-proposals', [
            'facet' => 'topic', 'value' => 'jornada',
        ], $this->auth($super))->assertStatus(201);

        $proposal = VocabularyProposal::where('facet', 'topic')->where('proposed_value', 'jornada')->firstOrFail();

        $this->postJson("/admin/vocabulary-proposals/{$proposal->id}/approve", [
            'resolution' => 'alias', 'target_id' => $existing->id,
        ], $this->auth($super))->assertStatus(422)->assertJsonFragment(['message' => 'Topics have no alias-fold mechanism — spelling/synonym variants are resolved in code via TopicLexicon, not the controlled vocabulary. Approve as new_value, or fix the TopicLexicon mapping directly if this is a known-topic spelling mismatch.']);
    }

    public function test_suggest_variant_for_topic_never_errors_despite_no_aliases_column(): void
    {
        Topic::create(['name' => 'permisos retribuidos', 'status' => 'approved']);
        $service = app(VocabularyProposalService::class);

        // Nothing close.
        $this->assertNull($service->suggestVariant('topic', 'preaviso'));

        // A near-identical existing name IS found via name-only matching (no
        // aliases column needed — the candidate list falls back to just the name).
        $variant = $service->suggestVariant('topic', 'permisos retribuido');
        $this->assertNotNull($variant);
        $this->assertSame('topic', $variant['type']);
    }

    /** @return array<string,string> */
    private function auth(Admin $admin): array
    {
        $this->app['auth']->forgetGuards();
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        return ['Authorization' => 'Bearer '.$admin->createToken('test')->plainTextToken, 'Accept' => 'application/json'];
    }
}
