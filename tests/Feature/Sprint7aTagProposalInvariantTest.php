<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AnswerModelSetting;
use App\Models\Convenio;
use App\Models\Document;
use App\Models\DocumentPage;
use App\Models\DocumentReviewTask;
use App\Models\DocumentType;
use App\Models\Sector;
use App\Models\TagEvent;
use App\Models\Territory;
use App\Models\Topic;
use App\Models\VocabularyProposal;
use App\Services\ExtractionClient;
use App\Services\TagProposalService;
use App\Services\VocabularyProposalService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Sprint 7a acceptance proof (ADR-0011/0020). The two NON-NEGOTIABLE safety
 * invariants of the LLM tagging tier, proven by test, plus the propose-vocabulary
 * authorization and the human-confirmed lineage write-side.
 *
 * INVARIANT 1 — AI proposals keep the document `under_review` (0 chunks,
 *   genuinely unretrievable per the embedding gate). Only the human verify flips
 *   it to `verified` (→ embeddable).
 * INVARIANT 2 — the AI writes ONLY `ai_agent` provenance, NEVER the authoritative
 *   FK columns (convenio_id / document_type_id / validity_* / retrieval_status).
 */
class Sprint7aTagProposalInvariantTest extends TestCase
{
    use RefreshDatabase;

    private Territory $territory;

    private Sector $sector;

    private Convenio $convenio;

    private DocumentType $convenioType;

    private Topic $topic;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);

        $this->territory = Territory::create(['code' => '20', 'name' => 'Gipuzkoa', 'level' => 'provincial', 'aliases' => []]);
        $this->sector = Sector::create(['name' => 'Comercio', 'aliases' => []]);
        $this->convenio = Convenio::create([
            'numero' => '20000125', 'name' => 'Comercio de Gipuzkoa',
            'territory_id' => $this->territory->id, 'sector_id' => $this->sector->id,
        ]);
        $this->convenioType = DocumentType::create(['code' => 'convenio_text', 'name' => 'Texto del convenio']);
        $this->topic = Topic::create(['name' => 'Vacaciones', 'status' => 'approved']);

        // Configure the answer-model key so the service is allowed to propose
        // (the proposeTags HTTP call itself is mocked — never a real provider).
        // current() resolves via id = 1; Postgres sequences are NOT rolled back
        // between tests (and `id` isn't mass-assignable), so we pin id = 1
        // explicitly — the proven Sprint-6 pattern.
        $setting = new AnswerModelSetting(['provider' => 'claude']);
        $setting->id = 1;
        $setting->save();
        $setting->setKey('sk-test-key-1234', null);
    }

    /**
     * An ingest-time `unresolved` document: under_review, in-scope prose type,
     * active retrieval (so the ONLY thing keeping it out of the embedding gate is
     * tagging_status), with extractable text + an open unresolved review task.
     */
    private function unresolvedDocument(): Document
    {
        $doc = Document::create([
            'title' => 'Convenio sin número (escaneo)',
            'source_filename' => 'gipuzkoa-comercio-escaneo.pdf',
            'storage_path' => 'docs/escaneo.pdf',
            'content_hash' => hash('sha256', 'escaneo'.uniqid()),
            'convenio_id' => null,            // unresolved at ingest (no scope yet)
            'document_type_id' => $this->convenioType->id,
            'validity_start' => null,
            'validity_end' => null,
            'retrieval_status' => 'active',
            'authority_level' => 'official_convenio',
            'language' => 'es',
            'tagging_status' => 'under_review', // the ingest state for unresolved
            'tagging_confidence' => null,
            'ingested_at' => now(),
        ]);
        DocumentPage::create(['document_id' => $doc->id, 'page_number' => 1, 'text' => 'Convenio colectivo del comercio de Gipuzkoa. Vacaciones: 30 días.']);
        DocumentReviewTask::create([
            'document_id' => $doc->id, 'type' => 'tag_review', 'reason' => 'unresolved',
            'raw_unmatched_values' => [['facet' => 'sector', 'value' => 'Comercio minorista']],
            'status' => 'open',
        ]);

        return $doc;
    }

    /**
     * A TagProposalService whose hr-ai call is stubbed to return a fixed proposal
     * envelope (no network, no real provider). Mirrors the /propose-tags contract.
     *
     * @param  array<string,mixed>  $envelope
     */
    private function serviceReturning(array $envelope): TagProposalService
    {
        $fakeClient = new class($envelope) extends ExtractionClient
        {
            /** @var array<string,mixed> */
            public array $envelope;

            public function __construct(array $envelope)
            {
                $this->envelope = $envelope;
            }

            public function proposeTags(int $documentId, string $pageText, array $candidateVocabulary, string $decryptedKey, array $providerConfig): array
            {
                return $this->envelope;
            }
        };

        return new TagProposalService($fakeClient);
    }

    /** The embedding gate, exactly as ChunksEmbed selects (the load-bearing query). */
    private function isEmbeddable(Document $doc): bool
    {
        return Document::query()
            ->whereKey($doc->id)
            ->whereHas('documentType', fn ($q) => $q->whereIn('code', ['convenio_text', 'national_law', 'partial_agreement', 'internal_hr_ruling']))
            ->whereIn('retrieval_status', ['active', 'historical'])
            ->where('tagging_status', '!=', 'under_review')
            ->exists();
    }

    // ---- INVARIANT 1 -------------------------------------------------------

    public function test_invariant_1_ai_proposal_keeps_document_under_review_and_zero_chunks(): void
    {
        $doc = $this->unresolvedDocument();
        $this->assertFalse($this->isEmbeddable($doc), 'precondition: under_review is excluded from the gate');

        $service = $this->serviceReturning([
            'facets' => [
                ['facet' => 'convenio', 'value_id' => $this->convenio->id, 'confidence' => 0.82],
                ['facet' => 'document_type', 'value_code' => 'convenio_text', 'confidence' => 0.9],
                ['facet' => 'sector', 'value_id' => $this->sector->id, 'confidence' => 0.7],
            ],
            'topics' => [['topic_id' => $this->topic->id, 'confidence' => 0.75]],
            'raw_unmatched_values' => [],
            'overall_confidence' => 0.7,
        ]);

        $service->propose($doc);

        $doc->refresh();
        // Still under_review — the AI NEVER wrote auto_proposed / verified.
        $this->assertSame('under_review', $doc->tagging_status);
        // Genuinely unretrievable: 0 chunks AND excluded from the gate.
        $this->assertSame(0, DB::table('document_chunks')->where('document_id', $doc->id)->count());
        $this->assertFalse($this->isEmbeddable($doc));

        // After a HUMAN verifies (the only writer of verified), it becomes eligible.
        $doc->update(['tagging_status' => 'verified']);
        $this->assertTrue($this->isEmbeddable($doc->refresh()));
    }

    // ---- INVARIANT 2 -------------------------------------------------------

    public function test_invariant_2_ai_writes_only_ai_agent_provenance_never_the_fk_columns(): void
    {
        $doc = $this->unresolvedDocument();
        $before = $doc->only(['convenio_id', 'document_type_id', 'validity_start', 'validity_end', 'retrieval_status']);

        $service = $this->serviceReturning([
            'facets' => [
                ['facet' => 'convenio', 'value_id' => $this->convenio->id, 'confidence' => 0.82],
                ['facet' => 'validity', 'value' => '2024-01-01..2027-12-31', 'confidence' => 0.6],
            ],
            'topics' => [['topic_id' => $this->topic->id, 'confidence' => 0.75]],
            'raw_unmatched_values' => [['facet' => 'sector', 'value' => 'Comercio minorista', 'variant_of' => ['id' => $this->sector->id, 'reason' => 'comercio']]],
            'overall_confidence' => 0.6,
        ]);

        $service->propose($doc);
        $doc->refresh();

        // The authoritative scope FKs / validity / retrieval are UNCHANGED.
        $this->assertSame($before['convenio_id'], $doc->convenio_id);
        $this->assertNull($doc->convenio_id, 'the AI must not bind the scope FK (convenio stays null)');
        $this->assertEquals($before['document_type_id'], $doc->document_type_id);
        $this->assertNull($doc->validity_start);
        $this->assertNull($doc->validity_end);
        $this->assertSame($before['retrieval_status'], $doc->retrieval_status);

        // It DID write ai_agent provenance + an unverified ai_agent topic + confidence.
        $aiEvents = TagEvent::where('entity_type', 'document')->where('entity_id', $doc->id)->where('source', 'ai_agent')->get();
        $this->assertGreaterThan(0, $aiEvents->count());
        $this->assertTrue($aiEvents->every(fn ($e) => $e->source === 'ai_agent'));
        $this->assertDatabaseHas('document_topics', [
            'document_id' => $doc->id, 'topic_id' => $this->topic->id, 'source' => 'ai_agent', 'verified_by' => null,
        ]);
        $this->assertNotNull($doc->tagging_confidence);

        // The AI's variant hint was merged into the review task (propose-vocab feeds off this).
        $task = DocumentReviewTask::where('document_id', $doc->id)->where('status', 'open')->first();
        $this->assertNotNull(collect($task->raw_unmatched_values)->firstWhere('facet', 'sector'));
    }

    // ---- Propose-vocabulary authorization ----------------------------------

    public function test_non_super_admin_can_propose_but_not_approve_vocabulary(): void
    {
        $editor = Admin::create(['email' => 'ke@example.com', 'full_name' => 'KE', 'status' => 'active']);
        $editor->assignRole('knowledge_editor');

        // Propose (knowledge.edit) — allowed; status stays `proposed`.
        $this->postJson('/admin/vocabulary-proposals', [
            'facet' => 'sector', 'value' => 'Comercio minorista',
        ], $this->auth($editor))->assertStatus(201)->assertJsonPath('proposal.status', 'proposed');

        // approve_now without vocabulary.approve → 403.
        $this->postJson('/admin/vocabulary-proposals', [
            'facet' => 'sector', 'value' => 'Otro sector', 'approve_now' => true, 'resolution' => 'new_value',
        ], $this->auth($editor))->assertStatus(403);

        // Approving an existing proposal as a non-super_admin → 403 (route gate).
        $proposal = VocabularyProposal::first();
        $this->postJson("/admin/vocabulary-proposals/{$proposal->id}/approve", [
            'resolution' => 'new_value',
        ], $this->auth($editor))->assertStatus(403);
    }

    public function test_super_admin_propose_and_approve_writes_new_sector(): void
    {
        $super = Admin::create(['email' => 'sa@example.com', 'full_name' => 'SA', 'status' => 'active']);
        $super->assignRole('super_admin');

        $this->postJson('/admin/vocabulary-proposals', [
            'facet' => 'sector', 'value' => 'Hostelería nueva', 'approve_now' => true, 'resolution' => 'new_value',
        ], $this->auth($super))->assertStatus(201);

        $this->assertDatabaseHas('sectors', ['name' => 'Hostelería nueva']);
        $this->assertDatabaseHas('vocabulary_proposals', ['proposed_value' => 'Hostelería nueva', 'status' => 'approved', 'resolution' => 'new_value']);
    }

    public function test_variant_to_alias_is_offered_and_folds_into_existing_value(): void
    {
        $service = app(VocabularyProposalService::class);
        // "Comercio" already exists as the sector name; a near spelling should
        // resolve as a variant above the threshold.
        $variant = $service->suggestVariant('sector', 'Comercios');
        $this->assertNotNull($variant);
        $this->assertSame($this->sector->id, $variant['id']);

        $proposal = $service->propose('sector', 'Comercios', ['proposed_by_source' => 'admin_manual']);
        $this->assertSame($this->sector->id, $proposal->variant_of_id);

        $service->approve($proposal, 'alias', ['target_id' => $this->sector->id]);
        $this->assertContains('Comercios', (array) $this->sector->refresh()->aliases);
    }

    // ---- Lineage write-side (human-confirmed succession) -------------------

    public function test_scope_based_succession_writes_predecessor_and_never_auto_retires(): void
    {
        $super = Admin::create(['email' => 'sa2@example.com', 'full_name' => 'SA2', 'status' => 'active']);
        $super->assignRole('super_admin'); // holds knowledge.edit

        $old = Document::create([
            'title' => 'Convenio 2020-2023', 'source_filename' => 'old.pdf', 'storage_path' => 'd/old.pdf',
            'content_hash' => hash('sha256', 'old'), 'convenio_id' => $this->convenio->id,
            'document_type_id' => $this->convenioType->id, 'validity_start' => '2020-01-01', 'validity_end' => '2023-12-31',
            'retrieval_status' => 'active', 'authority_level' => 'official_convenio', 'language' => 'es',
            'tagging_status' => 'verified', 'ingested_at' => now(),
        ]);
        $new = Document::create([
            'title' => 'Convenio 2024-2027', 'source_filename' => 'new.pdf', 'storage_path' => 'd/new.pdf',
            'content_hash' => hash('sha256', 'new'), 'convenio_id' => $this->convenio->id,
            'document_type_id' => $this->convenioType->id, 'validity_start' => '2024-01-01', 'validity_end' => '2027-12-31',
            'retrieval_status' => 'active', 'authority_level' => 'official_convenio', 'language' => 'es',
            'tagging_status' => 'verified', 'ingested_at' => now(),
        ]);
        $task = DocumentReviewTask::create(['document_id' => $old->id, 'type' => 'expiry', 'status' => 'open', 'due_date' => '2023-12-31']);

        // Link successor WITHOUT retire → predecessor stays active (never auto-retire).
        $this->postJson("/admin/review/expiry/{$task->id}/resolve", [
            'action' => 'link_successor', 'successor_uuid' => $new->uuid,
        ], $this->auth($super))->assertOk();

        $this->assertSame($old->id, $new->refresh()->predecessor_document_id);
        $this->assertSame('active', $old->refresh()->retrieval_status, 'predecessor is NEVER auto-retired');
    }

    public function test_cross_convenio_succession_is_rejected(): void
    {
        $super = Admin::create(['email' => 'sa3@example.com', 'full_name' => 'SA3', 'status' => 'active']);
        $super->assignRole('super_admin');

        $otherConvenio = Convenio::create(['numero' => '99999999', 'name' => 'Otro', 'territory_id' => $this->territory->id, 'sector_id' => $this->sector->id]);
        $old = Document::create([
            'title' => 'A', 'source_filename' => 'a.pdf', 'storage_path' => 'd/a.pdf', 'content_hash' => hash('sha256', 'a'),
            'convenio_id' => $this->convenio->id, 'document_type_id' => $this->convenioType->id,
            'retrieval_status' => 'active', 'authority_level' => 'official_convenio', 'language' => 'es',
            'tagging_status' => 'verified', 'ingested_at' => now(), 'validity_end' => '2023-12-31',
        ]);
        $otherDoc = Document::create([
            'title' => 'B', 'source_filename' => 'b.pdf', 'storage_path' => 'd/b.pdf', 'content_hash' => hash('sha256', 'b'),
            'convenio_id' => $otherConvenio->id, 'document_type_id' => $this->convenioType->id,
            'retrieval_status' => 'active', 'authority_level' => 'official_convenio', 'language' => 'es',
            'tagging_status' => 'verified', 'ingested_at' => now(),
        ]);
        $task = DocumentReviewTask::create(['document_id' => $old->id, 'type' => 'expiry', 'status' => 'open', 'due_date' => '2023-12-31']);

        $this->postJson("/admin/review/expiry/{$task->id}/resolve", [
            'action' => 'link_successor', 'successor_uuid' => $otherDoc->uuid,
        ], $this->auth($super))->assertStatus(422)->assertJsonPath('scope_based', true);

        $this->assertNull($otherDoc->refresh()->predecessor_document_id);
    }

    /** @return array<string,string> */
    private function auth(Admin $admin): array
    {
        $this->app['auth']->forgetGuards();
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        return ['Authorization' => 'Bearer '.$admin->createToken('test')->plainTextToken, 'Accept' => 'application/json'];
    }
}
