<?php

namespace Tests\Feature;

use App\Models\AnswerModelSetting;
use App\Models\Convenio;
use App\Models\Document;
use App\Models\DocumentPage;
use App\Models\DocumentType;
use App\Models\ReferenceFact;
use App\Models\Sector;
use App\Models\TagEvent;
use App\Models\Territory;
use App\Models\Topic;
use App\Services\ExtractionClient;
use App\Services\ReferenceFactProposalService;
use Database\Seeders\DocumentTypeSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Sprint 7b-2 acceptance proof (ADR-0022). The inherited invariants are
 * RE-PROVEN (not rebuilt) for the AI segmentation agent, with a STUBBED provider
 * (no hr-ai, no LLM, no network) so the persist contract is tested deterministic.
 *
 *  1. AI facts land `ai_agent` / `needs_review` / not-verified (inert).
 *  2. authority can only be `structured_reference` (the floor is forced).
 *  3. a reference_source segmentation writes ZERO salary rows.
 *  4. re-running is IDEMPOTENT (upsert on the group_label-extended logical key).
 *  5. the agent never calls verify() (no fact is ever verified by the agent).
 *  6. a logical-key collision with a DIFFERING value sets duplicate_of_id
 *     (a flag, never a merge — resolution is 7d).
 *
 * Plus the blocker fix (Q1): a file's per-group facts (G1/G2/G3) persist as
 * DISTINCT rows via `group_label` — not one clobbered row — and the cross-
 * province scoping (Álava vs Estatal, same group) is kept separate.
 */
class Sprint7b2SegmentationInvariantTest extends TestCase
{
    use RefreshDatabase;

    private Territory $alava;

    private Territory $estatal;

    private Sector $coeas;

    private Convenio $coeasAlava;

    private Convenio $coeasEstatal;

    private Topic $topic;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(DocumentTypeSeeder::class);

        $this->alava = Territory::create(['code' => '01', 'name' => 'Álava', 'level' => 'provincial', 'aliases' => ['Araba']]);
        $this->estatal = Territory::create(['code' => '00', 'name' => 'Estatal', 'level' => 'national', 'aliases' => []]);
        $this->coeas = Sector::create(['name' => 'Ocio Educativo y Animación Sociocultural', 'aliases' => ['COEAS']]);
        $this->coeasAlava = Convenio::create([
            'numero' => '01100000', 'name' => 'COEAS Álava',
            'territory_id' => $this->alava->id, 'sector_id' => $this->coeas->id,
        ]);
        $this->coeasEstatal = Convenio::create([
            'numero' => '99000000', 'name' => 'COEAS Estatal',
            'territory_id' => $this->estatal->id, 'sector_id' => $this->coeas->id,
        ]);
        // The 2026_06_26_120003 data migration already seeds this topic (Q6) —
        // fetch it (proving the lockstep seed) rather than re-creating it.
        $this->topic = Topic::firstOrCreate(['name' => 'periodo de prueba'], ['status' => 'approved']);

        // The provider key must be "configured" for propose() to proceed; the
        // stub never actually uses it (no network). current() resolves via id = 1,
        // and Postgres sequences are NOT rolled back between tests (and `id` isn't
        // mass-assignable), so pin id = 1 explicitly — the proven Sprint-6/7a
        // pattern — otherwise current() inside propose() finds no id=1 row and
        // creates a fresh UNCONFIGURED one, and the agent skips.
        $setting = new AnswerModelSetting(['provider' => 'claude']);
        $setting->id = 1;
        $setting->save();
        $setting->setKey('sk-test-not-used');
        config(['services.hr_ai.answer_model' => 'claude-test']);
    }

    // ---- Invariants 1, 2, 5 — inert / authority floor / never self-verify ----

    public function test_invariant_1_2_5_facts_land_inert_authority_floor_never_verified(): void
    {
        $doc = $this->referenceSource();
        $service = $this->serviceReturning([
            $this->fact($this->coeasAlava->id, 'Grupo 1', 'Cinco meses', 0.9),
        ]);

        $service->propose($doc);

        $fact = ReferenceFact::where('source', 'ai_agent')->firstOrFail();
        // (1) inert
        $this->assertSame('ai_agent', $fact->source);
        $this->assertSame('needs_review', $fact->status);
        $this->assertNull($fact->verified_at);
        $this->assertNull($fact->verified_by);
        // (2) authority floor
        $this->assertSame('structured_reference', $fact->authority_level);
        // (5) the agent never verifies — no verified fact, no ai_agent verify event
        $this->assertSame(0, ReferenceFact::where('status', 'verified')->count());
        $this->assertNull(
            TagEvent::where('entity_type', 'reference_fact')->where('new_value', 'verified')->first(),
            'the segmentation agent must never emit a verify event'
        );
        // provenance is appended as ai_agent
        $this->assertNotNull(
            TagEvent::where('entity_type', 'reference_fact')->where('entity_id', $fact->id)->where('source', 'ai_agent')->first()
        );
    }

    // ---- Invariant 3 — zero salary rows from the reference path -------------

    public function test_invariant_3_segmentation_writes_no_salary_rows(): void
    {
        $doc = $this->referenceSource();
        $service = $this->serviceReturning([
            $this->fact($this->coeasAlava->id, 'Grupo 1', 'Cinco meses', 0.9),
            $this->fact($this->coeasAlava->id, 'Grupo 2', 'Tres meses', 0.8),
        ]);

        $service->propose($doc);

        $this->assertSame(0, DB::table('salary_table_rows')->count());
        $this->assertSame(0, DB::table('salary_tables')->count());
    }

    // ---- Invariant 4 + the blocker (Q1) — idempotent, group_label distinct --

    public function test_invariant_4_and_blocker_distinct_groups_persist_and_rerun_is_idempotent(): void
    {
        $doc = $this->referenceSource();
        $envelope = [
            $this->fact($this->coeasAlava->id, 'Grupo 1', 'Cinco meses', 0.9),
            $this->fact($this->coeasAlava->id, 'Grupo 2', 'Tres meses', 0.85),
            $this->fact($this->coeasAlava->id, 'Grupo 3', 'Un mes', 0.8),
        ];

        // First run — the blocker fix: three DISTINCT facts, not one clobbered.
        $this->serviceReturning($envelope)->propose($doc);
        $this->assertSame(3, ReferenceFact::where('source', 'ai_agent')->count(), 'G1/G2/G3 must persist distinctly (group_label)');
        $labels = ReferenceFact::where('source', 'ai_agent')->pluck('group_label')->sort()->values()->all();
        $this->assertSame(['Grupo 1', 'Grupo 2', 'Grupo 3'], $labels);

        // Re-run the SAME envelope — idempotent UPSERT on the extended key.
        $this->serviceReturning($envelope)->propose($doc);
        $this->assertSame(3, ReferenceFact::where('source', 'ai_agent')->count(), 're-running must upsert, not duplicate');
    }

    public function test_cross_province_same_group_stays_separate(): void
    {
        $doc = $this->referenceSource();
        $this->serviceReturning([
            // Same group "Grupo 1", different convenio (province) → two facts.
            $this->fact($this->coeasAlava->id, 'Grupo 1', 'Cinco meses', 0.9),
            $this->fact($this->coeasEstatal->id, 'Grupo 1', 'Seis meses', 0.9),
        ])->propose($doc);

        $alavaFact = ReferenceFact::where('convenio_id', $this->coeasAlava->id)->firstOrFail();
        $estatalFact = ReferenceFact::where('convenio_id', $this->coeasEstatal->id)->firstOrFail();
        $this->assertSame('Cinco meses', $alavaFact->value);
        $this->assertSame('Seis meses', $estatalFact->value);
        $this->assertSame('Álava', $alavaFact->convenio->territory->name);
        $this->assertSame('Estatal', $estatalFact->convenio->territory->name);
    }

    // ---- Invariant 6 — version/duplicate is FLAGGED, never merged -----------

    public function test_invariant_6_same_scope_different_value_sets_duplicate_flag_without_merging(): void
    {
        // An existing (file-1) fact: COEAS Álava, Grupo 2, "Seis meses".
        $existing = ReferenceFact::create([
            'convenio_id' => $this->coeasAlava->id,
            'topic_id' => $this->topic->id,
            'group_label' => 'Grupo 2',
            'value' => 'Seis meses',
            'authority_level' => ReferenceFact::AUTHORITY_LEVEL,
            'source' => 'admin_manual',
            'status' => 'verified',
        ]);

        // File-2 segmentation: SAME scope, DIFFERING value "Cuatro meses".
        $doc = $this->referenceSource();
        $this->serviceReturning([
            $this->fact($this->coeasAlava->id, 'Grupo 2', 'Cuatro meses', 0.85),
        ])->propose($doc);

        $new = ReferenceFact::where('source', 'ai_agent')->firstOrFail();
        $this->assertSame($existing->id, $new->duplicate_of_id, 'a same-scope/different-value collision is flagged');
        $this->assertSame('version', $new->uncertainty['field'] ?? null);
        // No merge: the existing fact is untouched (still verified, value intact).
        $existing->refresh();
        $this->assertSame('Seis meses', $existing->value);
        $this->assertSame('verified', $existing->status);
    }

    // ---- helpers ------------------------------------------------------------

    /** A persisted reference_source document with one page + a validity window. */
    private function referenceSource(): Document
    {
        $type = DocumentType::where('code', 'reference_source')->firstOrFail();
        $doc = Document::create([
            'title' => 'PERIODOS DE PRUEBA',
            'source_filename' => 'PERIODOS DE PRUEBA.docx',
            'storage_path' => 'documents/test/original.docx',
            'content_hash' => bin2hex(random_bytes(16)),
            'document_type_id' => $type->id,
            'validity_start' => '2024-01-01',
            'validity_end' => '2027-12-31',
            'language' => 'es',
            'retrieval_status' => 'active',
            // The DOCUMENT-level authority enum (unrelated to the FACT authority,
            // which is forced to structured_reference). reference_source docs are
            // never embedded/answered, so the value is immaterial here.
            'authority_level' => 'official_convenio',
            'tagging_status' => 'under_review',
        ]);
        DocumentPage::create(['document_id' => $doc->id, 'page_number' => 1, 'text' => 'ALAVA\nCOEAS\nGrupo 1: Cinco meses']);

        return $doc;
    }

    /**
     * One fact in the hr-ai /segment-facts envelope shape (post-validation).
     *
     * @return array<string,mixed>
     */
    private function fact(int $convenioId, ?string $group, string $value, float $confidence): array
    {
        return [
            'convenio_id' => $convenioId,
            'job_category_id' => null,
            'group_label' => $group,
            'topic_id' => $this->topic->id,
            'value' => $value,
            'raw_values' => null,
            'confidence' => $confidence,
            'uncertainty' => null,
            'source_locator' => 'p.1',
            'source_excerpt' => "ALAVA › COEAS › {$group}: {$value}",
        ];
    }

    /**
     * The persist service wired with a STUBBED ExtractionClient that returns the
     * given facts envelope — no hr-ai call, no LLM, no network.
     *
     * @param  list<array<string,mixed>>  $facts
     */
    private function serviceReturning(array $facts): ReferenceFactProposalService
    {
        $fakeAi = new class($facts) extends ExtractionClient
        {
            /** @param list<array<string,mixed>> $facts */
            public function __construct(private array $facts) {}

            public function segmentFacts(
                int $documentId,
                string $documentUuid,
                string $sourceFormat,
                string $pagesText,
                array $candidateConvenios,
                array $candidateTopics,
                string $decryptedKey,
                array $providerConfig,
            ): array {
                return ['facts' => $this->facts, 'trace_fragment' => ['stub' => true]];
            }
        };

        return new ReferenceFactProposalService($fakeAi);
    }
}
