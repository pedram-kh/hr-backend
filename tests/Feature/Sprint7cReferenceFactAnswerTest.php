<?php

namespace Tests\Feature;

use App\Models\AnswerModelSetting;
use App\Models\Convenio;
use App\Models\ConvenioJobCategory;
use App\Models\Document;
use App\Models\Employee;
use App\Models\MessageTrace;
use App\Models\ReferenceFact;
use App\Models\Sector;
use App\Models\Territory;
use App\Models\Topic;
use App\Services\ChatService;
use App\Services\ExtractionClient;
use App\Services\ReferenceFactAnswerService;
use App\Services\ReferenceFactRouter;
use Database\Seeders\DocumentTypeSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Sprint 7c Phase 1 acceptance proof (ADR-0023) — the routed reference-fact
 * answer, and the NON-NEGOTIABLE safety spine: ONLY a `verified` fact ever
 * answers (the inert-until-verified gate of all 7b connecting to chat).
 *
 * Tested hardest: an unverified / rejected / out-of-validity / future-only fact,
 * and a per-group-only fact whose group can't be confidently resolved, are NEVER
 * quoted — they escalate `reference_fact_coverage_gap`, never a guess. A bug here
 * undoes all of 7b's safety.
 *
 * Also pins the skip-ground (P1) discipline: a reference-fact answer carries NO
 * grounding block (a quoted verified value — nothing generated to entail).
 */
class Sprint7cReferenceFactAnswerTest extends TestCase
{
    use RefreshDatabase;

    private Convenio $convenio;

    private Topic $topic;

    private Document $sourceDoc;

    private Territory $territory;

    private Sector $sector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(DocumentTypeSeeder::class);

        $this->territory = Territory::create(['code' => '31', 'name' => 'Navarra', 'level' => 'provincial', 'aliases' => []]);
        $this->sector = Sector::create(['name' => 'Hostelería', 'aliases' => []]);
        $this->convenio = Convenio::create([
            'numero' => '31000505', 'name' => 'Hostelería de Navarra',
            'territory_id' => $this->territory->id, 'sector_id' => $this->sector->id,
        ]);
        // The pre-check maps "periodo de prueba" (lexicon anchor) → this approved topic.
        $this->topic = Topic::firstOrCreate(['name' => 'periodo de prueba'], ['status' => 'approved']);
        $this->sourceDoc = Document::create([
            'title' => 'Periodos de prueba (referencia)', 'storage_path' => 'fake/ref.docx',
            'convenio_id' => $this->convenio->id, 'document_type_id' => \App\Models\DocumentType::query()->value('id'),
            'authority_level' => 'official_convenio', 'retrieval_status' => 'active', 'language' => 'es',
            'tagging_status' => 'verified',
        ]);
    }

    // ---- The happy path: a VERIFIED fact answers in chat (P1 eyes-on) --------

    public function test_verified_fact_answers_in_chat_at_structured_reference_and_skips_ground(): void
    {
        $employee = $this->employee();
        $this->verifiedFact(value: 'periodo de prueba 90/75/60 días según contrato', group: null, jobCategory: null);

        // Bind an AI that would EXPLODE if called — proves the reference-fact path
        // never touches /route, /synthesise, or /ground (skip-ground by construction).
        $this->bindExplodingAi();

        $result = app(ChatService::class)->handleMessage($employee, '¿cuál es mi periodo de prueba?');

        $this->assertSame('answer', $result['outcome']);
        $this->assertStringContainsString('90/75/60 días', $result['answer']);

        // The citation is the source doc with chunk_id = null at structured_reference.
        $this->assertCount(1, $result['citations']);
        $this->assertNull($result['citations'][0]['chunk_id']);
        $this->assertTrue($result['citations'][0]['is_reference_fact']);
        $this->assertSame('structured_reference', $result['citations'][0]['authority_level']);
        $this->assertSame($this->sourceDoc->id, $result['citations'][0]['document_id']);

        $trace = MessageTrace::firstOrFail()->trace;
        $this->assertSame('reference_fact', $trace['floor_decision']['path']);
        $this->assertSame('answer', $trace['floor_decision']['outcome']);
        $this->assertSame(['structured_reference'], $trace['floor_decision']['authority_used']);
        $this->assertArrayHasKey('reference_fact', $trace);
        $this->assertNotNull($trace['reference_fact']['fact_id']);
        $this->assertSame('deterministic_reference_fact', $trace['router_decision']['source']);

        // SKIP-GROUND (P1): no grounding block at all (nothing generated to entail).
        $this->assertArrayNotHasKey('grounding', $trace['floor_decision']);
        $this->assertArrayNotHasKey('synthesis', $trace);
    }

    // ---- THE SAFETY SPINE: only verified answers (test hardest) --------------

    public function test_needs_review_fact_is_never_quoted_precheck_falls_through(): void
    {
        $employee = $this->employee();
        // A fact that matches EVERYTHING except status — must never be reachable.
        ReferenceFact::create($this->factAttrs(value: 'periodo 999 días', status: 'needs_review'));

        // Pre-check finds NO verified fact → returns null (fall through).
        $detection = app(ReferenceFactRouter::class)->detectTopic($employee, '¿cuál es mi periodo de prueba?', Carbon::today());
        $this->assertNull($detection, 'a needs_review fact must not make the route reachable');
    }

    public function test_rejected_fact_is_never_quoted(): void
    {
        $employee = $this->employee();
        ReferenceFact::create($this->factAttrs(value: 'periodo rechazado', status: 'rejected'));

        $this->assertNull(app(ReferenceFactRouter::class)->detectTopic($employee, '¿cuál es mi periodo de prueba?', Carbon::today()));
        // The service itself also refuses (defence in depth).
        $out = app(ReferenceFactAnswerService::class)->answer($employee, $this->topic->id, Carbon::today());
        $this->assertSame('escalate', $out['outcome']);
        $this->assertSame('reference_fact_coverage_gap', $out['escalation_reason']);
    }

    public function test_future_only_verified_fact_is_not_quoted_and_escalates(): void
    {
        $employee = $this->employee();
        // Verified, but only effective NEXT year (future-only) → never quote.
        ReferenceFact::create($this->factAttrs(
            value: 'periodo futuro', status: 'verified',
            validityStart: Carbon::today()->addYear()->toDateString(),
        ));

        $this->assertNull(app(ReferenceFactRouter::class)->detectTopic($employee, '¿cuál es mi periodo de prueba?', Carbon::today()));
        $out = app(ReferenceFactAnswerService::class)->answer($employee, $this->topic->id, Carbon::today());
        $this->assertSame('escalate', $out['outcome']);
        $this->assertSame('reference_fact_coverage_gap', $out['escalation_reason']);
    }

    public function test_expired_verified_fact_is_not_quoted_and_escalates(): void
    {
        $employee = $this->employee();
        ReferenceFact::create($this->factAttrs(
            value: 'periodo caducado', status: 'verified',
            validityStart: '2018-01-01', validityEnd: '2019-12-31',
        ));

        $this->assertNull(app(ReferenceFactRouter::class)->detectTopic($employee, '¿cuál es mi periodo de prueba?', Carbon::today()));
    }

    // ---- Q2: most-specific ELSE ESCALATE — never guess a group ---------------

    public function test_per_group_only_fact_with_unresolved_group_escalates_not_guesses(): void
    {
        // Employee has NO job category → group cannot be confidently resolved.
        $employee = $this->employee(jobCategoryId: null);
        // Only per-group verified facts exist (no convenio-wide fact).
        ReferenceFact::create($this->factAttrs(value: 'Grupo 1: 90 días', status: 'verified', groupLabel: 'Grupo 1'));
        ReferenceFact::create($this->factAttrs(value: 'Grupo 2: 60 días', status: 'verified', groupLabel: 'Grupo 2'));

        $out = app(ReferenceFactAnswerService::class)->answer($employee, $this->topic->id, Carbon::today());
        $this->assertSame('escalate', $out['outcome'], 'never answer from a guessed group');
        $this->assertSame('reference_fact_coverage_gap', $out['escalation_reason']);
    }

    public function test_per_group_fact_answers_when_employee_group_resolves(): void
    {
        $cat = ConvenioJobCategory::create(['convenio_id' => $this->convenio->id, 'name' => 'Camarero', 'group_code' => '1']);
        $employee = $this->employee(jobCategoryId: $cat->id);
        ReferenceFact::create($this->factAttrs(value: 'Grupo 1: 90 días', status: 'verified', groupLabel: 'Grupo 1'));
        ReferenceFact::create($this->factAttrs(value: 'Grupo 2: 60 días', status: 'verified', groupLabel: 'Grupo 2'));

        $out = app(ReferenceFactAnswerService::class)->answer($employee, $this->topic->id, Carbon::today());
        $this->assertSame('answer', $out['outcome']);
        $this->assertStringContainsString('90 días', $out['answer']);
        $this->assertSame('group_label', $out['reference_fact']['match_kind']);
    }

    public function test_job_category_fact_is_most_specific_and_wins_over_group_and_wide(): void
    {
        $cat = ConvenioJobCategory::create(['convenio_id' => $this->convenio->id, 'name' => 'Cocinero', 'group_code' => '1']);
        $employee = $this->employee(jobCategoryId: $cat->id);
        ReferenceFact::create($this->factAttrs(value: 'convenio-wide 70 días', status: 'verified'));
        ReferenceFact::create($this->factAttrs(value: 'Grupo 1: 80 días', status: 'verified', groupLabel: 'Grupo 1'));
        ReferenceFact::create($this->factAttrs(value: 'categoría: 95 días', status: 'verified', jobCategoryId: $cat->id));

        $out = app(ReferenceFactAnswerService::class)->answer($employee, $this->topic->id, Carbon::today());
        $this->assertSame('answer', $out['outcome']);
        $this->assertStringContainsString('95 días', $out['answer']);
        $this->assertSame('job_category', $out['reference_fact']['match_kind']);
    }

    // ---- Two-verified-match safe rule ---------------------------------------

    public function test_two_verified_same_validity_conflict_escalates_never_blends(): void
    {
        $employee = $this->employee();
        // Two convenio-wide verified facts, SAME validity window, DIFFERING value.
        ReferenceFact::create($this->factAttrs(value: '90 días', status: 'verified', validityStart: '2024-01-01'));
        ReferenceFact::create($this->factAttrs(value: '75 días', status: 'verified', validityStart: '2024-01-01'));

        $out = app(ReferenceFactAnswerService::class)->answer($employee, $this->topic->id, Carbon::today());
        $this->assertSame('escalate', $out['outcome'], 'same-validity conflict escalates, never blends (7d resolves)');
        $this->assertSame('ambiguous_conflict', $out['reference_fact']['validity_selection']);
    }

    public function test_two_verified_different_validity_picks_most_recent(): void
    {
        $employee = $this->employee();
        // BOTH currently valid (open-ended), different start → the newer supersedes.
        ReferenceFact::create($this->factAttrs(value: 'viejo 90 días', status: 'verified', validityStart: '2022-01-01'));
        ReferenceFact::create($this->factAttrs(value: 'nuevo 75 días', status: 'verified', validityStart: '2024-01-01'));

        $out = app(ReferenceFactAnswerService::class)->answer($employee, $this->topic->id, Carbon::today());
        $this->assertSame('answer', $out['outcome']);
        $this->assertStringContainsString('75 días', $out['answer']);
        $this->assertSame('most_recent_validity', $out['reference_fact']['validity_selection']);
    }

    // ---- helpers ------------------------------------------------------------

    private function employee(?int $jobCategoryId = -1): Employee
    {
        // Default: create a category in the convenio so the happy path has a scope;
        // pass null explicitly to test the unresolved-group path.
        if ($jobCategoryId === -1) {
            $jobCategoryId = null;
        }

        return Employee::create([
            'email' => 'emp'.uniqid().'@example.com', 'full_name' => 'Empleada',
            'convenio_id' => $this->convenio->id, 'job_category_id' => $jobCategoryId,
            'territory_id' => $this->territory->id, 'employment_type' => 'full_time', 'status' => 'active',
        ]);
    }

    private function verifiedFact(string $value, ?string $group, ?int $jobCategory): ReferenceFact
    {
        return ReferenceFact::create($this->factAttrs(value: $value, status: 'verified', groupLabel: $group, jobCategoryId: $jobCategory));
    }

    /** @return array<string,mixed> */
    private function factAttrs(
        string $value,
        string $status,
        ?string $groupLabel = null,
        ?int $jobCategoryId = null,
        ?string $validityStart = null,
        ?string $validityEnd = null,
    ): array {
        return [
            'convenio_id' => $this->convenio->id,
            'topic_id' => $this->topic->id,
            'job_category_id' => $jobCategoryId,
            'group_label' => $groupLabel,
            'value' => $value,
            'authority_level' => 'structured_reference',
            'source' => 'admin_manual',
            'status' => $status,
            'validity_start' => $validityStart,
            'validity_end' => $validityEnd,
            'source_document_id' => $this->sourceDoc->id,
            'source_locator' => 'p.3 §2',
        ];
    }

    private function bindExplodingAi(): void
    {
        $fake = new class extends ExtractionClient
        {
            public function __construct() {}

            public function route(string $q, string $k, array $c): array
            {
                throw new \RuntimeException('the reference-fact path must NOT call /route');
            }

            public function synthesise(string $q, array $ch, string $k, array $c): array
            {
                throw new \RuntimeException('the reference-fact path must NOT call /synthesise');
            }

            public function ground(string $q, string $a, array $ch, string $k, array $c): array
            {
                throw new \RuntimeException('the reference-fact path must NOT call /ground (skip-ground, P1)');
            }
        };
        $this->app->instance(ExtractionClient::class, $fake);
        // Force the answer-model row at id=1 (sequences aren't rolled back between
        // test classes; current() looks up id=1). The reference-fact path never
        // reads the key — this only keeps the fixture honest.
        AnswerModelSetting::query()->delete();
        $s = new AnswerModelSetting(['provider' => 'claude']);
        $s->id = 1;
        $s->setKey('test-key-1234');
    }
}
