<?php

namespace Tests\Feature;

use App\Models\AnswerModelSetting;
use App\Models\Convenio;
use App\Models\ConvenioGroup;
use App\Models\ConvenioJobCategory;
use App\Models\Document;
use App\Models\Employee;
use App\Models\MessageTrace;
use App\Models\ReferenceFact;
use App\Models\ReferenceFactGroupScope;
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

        // Bind an AI that returns NO retrieval (so Phase 2 composition finds no
        // governing convenio prose on the topic and correctly falls through to the
        // Phase 1 quote) and EXPLODES on /route, /synthesise, /ground — proving the
        // Phase 1 fallback never touches them (skip-ground by construction).
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

    /**
     * The three tests below kept their invariants across Sprint 7f and changed
     * only how a group is expressed: an approved node id instead of a digit
     * found in free text. Rewritten, never weakened.
     */
    public function test_per_group_only_fact_with_no_employee_group_escalates_not_guesses(): void
    {
        // Employee has no group node → Tier 2 is skipped entirely.
        $employee = $this->employee();
        $g1 = $this->node('Grupo 1', '1');
        $g2 = $this->node('Grupo 2', '2');
        // Only per-group verified facts exist (no convenio-wide fact).
        $this->bind($this->verifiedFact('Grupo 1: 90 días', 'Grupo 1', null), $g1);
        $this->bind($this->verifiedFact('Grupo 2: 60 días', 'Grupo 2', null), $g2);

        $out = app(ReferenceFactAnswerService::class)->answer($employee, $this->topic->id, Carbon::today());
        $this->assertSame('escalate', $out['outcome'], 'never answer from a guessed group');
        $this->assertSame('reference_fact_coverage_gap', $out['escalation_reason']);
    }

    public function test_per_group_fact_answers_when_the_employee_sits_on_that_node(): void
    {
        $g1 = $this->node('Grupo 1', '1');
        $g2 = $this->node('Grupo 2', '2');
        $employee = $this->employee(groupId: $g1->id);
        $this->bind($this->verifiedFact('Grupo 1: 90 días', 'Grupo 1', null), $g1);
        $this->bind($this->verifiedFact('Grupo 2: 60 días', 'Grupo 2', null), $g2);

        $out = app(ReferenceFactAnswerService::class)->answer($employee, $this->topic->id, Carbon::today());
        $this->assertSame('answer', $out['outcome']);
        $this->assertStringContainsString('90 días', $out['answer']);
        $this->assertSame('group', $out['reference_fact']['match_kind']);
        $this->assertSame($g1->id, $out['reference_fact']['group_node_id']);
        $this->assertSame('Grupo 1', $out['reference_fact']['group_node_label']);
    }

    public function test_job_category_fact_is_most_specific_and_wins_over_group_and_wide(): void
    {
        $cat = ConvenioJobCategory::create(['convenio_id' => $this->convenio->id, 'name' => 'Cocinero', 'group_code' => '1']);
        $g1 = $this->node('Grupo 1', '1');
        $employee = $this->employee(jobCategoryId: $cat->id, groupId: $g1->id);
        ReferenceFact::create($this->factAttrs(value: 'convenio-wide 70 días', status: 'verified'));
        $this->bind($this->verifiedFact('Grupo 1: 80 días', 'Grupo 1', null), $g1);
        ReferenceFact::create($this->factAttrs(value: 'categoría: 95 días', status: 'verified', jobCategoryId: $cat->id));

        $out = app(ReferenceFactAnswerService::class)->answer($employee, $this->topic->id, Carbon::today());
        $this->assertSame('answer', $out['outcome']);
        $this->assertStringContainsString('95 días', $out['answer']);
        $this->assertSame('job_category', $out['reference_fact']['match_kind']);
    }

    // ---- Sprint 7f (ADR-0028): the exact matcher, tests (a)–(h) -------------
    //
    // Built on a convenio-21-shaped fixture: G1 undivided, G2 split into
    // "área 5" / "resto áreas", G3 undivided, and NO job categories — the real
    // shape of the convenio this sprint exists for. The compound fact is the
    // one that broke the old matcher.

    /**
     * (a) The bug, stated as a test. The employee is in "resto áreas"; the
     * 90-day fact's label is "Grupo 1 (todas las áreas) y Grupo 2 (área 5)".
     * The old digit matcher found "2" in that string and answered 90 días to
     * someone the convenio gives 60. Confident, cited, wrong.
     */
    public function test_a_employee_in_resto_areas_gets_60_never_the_area_5_value(): void
    {
        $f = $this->navarra();
        $employee = $this->employee(groupId: $f['resto']->id);

        $out = app(ReferenceFactAnswerService::class)->answer($employee, $this->topic->id, Carbon::today());

        $this->assertSame('answer', $out['outcome']);
        $this->assertStringContainsString('60 días', $out['answer']);
        $this->assertStringNotContainsString('90 días', $out['answer'], 'the exact 7b-2 bug');
        $this->assertSame('group', $out['reference_fact']['match_kind']);
        $this->assertSame($f['resto']->id, $out['reference_fact']['group_node_id']);
    }

    /**
     * (b) The employee is known only to the group level, but the convenio pays
     * differently by sub-area. There is no answer that is both correct and
     * specific, so the only honest move is to escalate.
     */
    public function test_b_employee_on_a_split_parent_escalates_rather_than_picking_a_slice(): void
    {
        $f = $this->navarra();
        $employee = $this->employee(groupId: $f['g2']->id);

        $out = app(ReferenceFactAnswerService::class)->answer($employee, $this->topic->id, Carbon::today());

        $this->assertSame('escalate', $out['outcome']);
        $this->assertSame('reference_fact_coverage_gap', $out['escalation_reason']);
        $this->assertStringContainsString('sub-area', (string) $out['reference_fact']['note']);
    }

    /**
     * (c) The case a single `group_id` column on `reference_facts` could not
     * have served: one fact, two groups, and this employee is in the other one.
     */
    public function test_c_employee_in_grupo_1_gets_90_from_the_compound_fact(): void
    {
        $f = $this->navarra();
        $employee = $this->employee(groupId: $f['g1']->id);

        $out = app(ReferenceFactAnswerService::class)->answer($employee, $this->topic->id, Carbon::today());

        $this->assertSame('answer', $out['outcome']);
        $this->assertStringContainsString('90 días', $out['answer']);
        $this->assertSame($f['g1']->id, $out['reference_fact']['group_node_id']);
    }

    /** (d) An undivided group matches on the group alone — same comparison, no special case. */
    public function test_d_an_undivided_group_matches_on_the_group(): void
    {
        $g1 = $this->node('Grupo 1', '1');
        $employee = $this->employee(groupId: $g1->id);
        $this->bind($this->verifiedFact('Grupo 1: 30 días', 'Grupo 1', null), $g1);

        $out = app(ReferenceFactAnswerService::class)->answer($employee, $this->topic->id, Carbon::today());

        $this->assertSame('answer', $out['outcome']);
        $this->assertStringContainsString('30 días', $out['answer']);
    }

    /**
     * (e) A `group_label` nobody has bound is a claim nobody has vouched for.
     * It cannot match Tier 2 (no nodes) and cannot satisfy Tier 3 (which needs
     * a null label), so it escalates — the label alone never answers.
     */
    public function test_e_a_fact_with_a_label_but_no_binding_is_never_group_matchable(): void
    {
        $g1 = $this->node('Grupo 1', '1');
        $employee = $this->employee(groupId: $g1->id);
        $this->verifiedFact('Grupo 1: 90 días', 'Grupo 1', null); // deliberately unbound

        $out = app(ReferenceFactAnswerService::class)->answer($employee, $this->topic->id, Carbon::today());

        $this->assertSame('escalate', $out['outcome']);
        $this->assertSame('reference_fact_coverage_gap', $out['escalation_reason']);
    }

    /** (f) "Grupo 12" is not group 1 and not group 2. The regex that thought otherwise is gone. */
    public function test_f_grupo_12_matches_neither_grupo_1_nor_grupo_2(): void
    {
        $g1 = $this->node('Grupo 1', '1');
        $g2 = $this->node('Grupo 2', '2');
        $g12 = $this->node('Grupo 12', '12');
        $this->bind($this->verifiedFact('Grupo 12: 120 días', 'Grupo 12', null), $g12);

        foreach ([$g1, $g2] as $node) {
            $out = app(ReferenceFactAnswerService::class)
                ->answer($this->employee(groupId: $node->id), $this->topic->id, Carbon::today());
            $this->assertSame('escalate', $out['outcome'], "node {$node->label} must not match Grupo 12");
        }
    }

    /**
     * (g) The compound fact is ONE fact reached from two nodes — not two copies
     * that could drift apart. Same value, same citation, from either side.
     */
    public function test_g_the_compound_fact_reads_identically_from_both_of_its_nodes(): void
    {
        $f = $this->navarra();

        $fromG1 = app(ReferenceFactAnswerService::class)
            ->answer($this->employee(groupId: $f['g1']->id), $this->topic->id, Carbon::today());
        $fromArea5 = app(ReferenceFactAnswerService::class)
            ->answer($this->employee(groupId: $f['area5']->id), $this->topic->id, Carbon::today());

        $this->assertSame($fromG1['answer'], $fromArea5['answer']);
        $this->assertSame($fromG1['citations'], $fromArea5['citations']);
        $this->assertSame($fromG1['reference_fact']['fact_id'], $fromArea5['reference_fact']['fact_id']);
        // The node differs — that is the only thing that should.
        $this->assertNotSame(
            $fromG1['reference_fact']['group_node_id'],
            $fromArea5['reference_fact']['group_node_id'],
        );
    }

    /**
     * (h) The no-regression case, and the one that covers every real profile on
     * the day this ships: with no group node, the ladder behaves exactly as it
     * did before 7f — Tier 2 is skipped and Tier 3 answers.
     */
    public function test_h_an_employee_with_no_group_node_behaves_exactly_as_before(): void
    {
        $f = $this->navarra();
        ReferenceFact::create($this->factAttrs(value: 'convenio-wide 70 días', status: 'verified'));
        $employee = $this->employee(); // convenio_group_id null, as all 1,500 real profiles are

        $out = app(ReferenceFactAnswerService::class)->answer($employee, $this->topic->id, Carbon::today());

        $this->assertSame('answer', $out['outcome']);
        $this->assertStringContainsString('70 días', $out['answer']);
        $this->assertSame('convenio_wide', $out['reference_fact']['match_kind']);
        $this->assertArrayNotHasKey('group_node_id', $out['reference_fact'],
            'the trace shape of a non-group answer must not change');
        $this->assertNotNull($f['g2']); // the tree exists and is simply not consulted
    }

    /**
     * Rule (3), the mirror of (b): the employee's sub-area is known, but the
     * FACT claims a group the convenio has since split. The ambiguity is on the
     * fact's side this time, and the outcome is the same.
     */
    public function test_a_group_level_fact_on_a_split_group_does_not_answer_a_sub_area_employee(): void
    {
        $g2 = $this->node('Grupo 2', '2');
        $area5 = $this->node('área 5', 'area-5', $g2);
        $this->node('resto áreas', 'resto-areas', $g2);
        $this->bind($this->verifiedFact('Grupo 2: 60 días', 'Grupo 2', null), $g2);
        $employee = $this->employee(groupId: $area5->id);

        $out = app(ReferenceFactAnswerService::class)->answer($employee, $this->topic->id, Carbon::today());

        $this->assertSame('escalate', $out['outcome']);
        $this->assertStringContainsString('ambiguous', (string) $out['reference_fact']['note']);
    }

    /**
     * The hard stop, stated on its own: an indeterminate scope must not fall
     * through to a convenio-wide answer. Tier 3 has a perfectly good fact here
     * and Tier 2 still refuses — because answering convenio-wide would be less
     * specific than the evidence, delivered with the same confidence.
     */
    public function test_an_indeterminate_group_does_not_fall_through_to_the_convenio_wide_fact(): void
    {
        $f = $this->navarra();
        ReferenceFact::create($this->factAttrs(value: 'convenio-wide 70 días', status: 'verified'));
        $employee = $this->employee(groupId: $f['g2']->id);

        $out = app(ReferenceFactAnswerService::class)->answer($employee, $this->topic->id, Carbon::today());

        $this->assertSame('escalate', $out['outcome']);
        $this->assertStringNotContainsString('70 días', (string) ($out['answer'] ?? ''));
    }

    /**
     * A node rejected out from under an assigned employee is not a match and
     * not an escalation: the ladder continues as if no group were set, which is
     * where a null node already lands. Rejecting a node must not start
     * escalating turns that used to answer.
     */
    public function test_a_rejected_node_lands_where_a_null_node_lands(): void
    {
        $g1 = $this->node('Grupo 1', '1');
        $employee = $this->employee(groupId: $g1->id);
        $this->bind($this->verifiedFact('Grupo 1: 90 días', 'Grupo 1', null), $g1);
        ReferenceFact::create($this->factAttrs(value: 'convenio-wide 70 días', status: 'verified'));

        $g1->update(['status' => ConvenioGroup::STATUS_REJECTED]);

        $out = app(ReferenceFactAnswerService::class)->answer($employee, $this->topic->id, Carbon::today());

        $this->assertSame('answer', $out['outcome']);
        $this->assertStringContainsString('70 días', $out['answer']);
        $this->assertSame('convenio_wide', $out['reference_fact']['match_kind']);
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

    private function employee(?int $jobCategoryId = null, ?int $groupId = null): Employee
    {
        return Employee::create([
            'email' => 'emp'.uniqid().'@example.com', 'full_name' => 'Empleada',
            'convenio_id' => $this->convenio->id, 'job_category_id' => $jobCategoryId,
            'convenio_group_id' => $groupId,
            'territory_id' => $this->territory->id, 'employment_type' => 'full_time', 'status' => 'active',
        ]);
    }

    /** An APPROVED node — Tier 2 only ever compares against approved structure. */
    private function node(string $label, string $code, ?ConvenioGroup $parent = null): ConvenioGroup
    {
        return ConvenioGroup::create([
            'convenio_id' => $this->convenio->id,
            'parent_id' => $parent?->id,
            'label' => $label,
            'code_normalized' => $code,
            'normalization_rule' => 'test',
            'status' => ConvenioGroup::STATUS_APPROVED,
            'source' => 'admin_manual',
        ]);
    }

    private function bind(ReferenceFact $fact, ConvenioGroup ...$nodes): ReferenceFact
    {
        foreach ($nodes as $node) {
            ReferenceFactGroupScope::create([
                'reference_fact_id' => $fact->id,
                'convenio_group_id' => $node->id,
                'bound_at' => now(),
            ]);
        }

        return $fact;
    }

    /**
     * Convenio 21's real shape: G1 and G3 undivided, G2 split into "área 5" and
     * "resto áreas", no job categories. The 90-day fact is COMPOUND — one fact,
     * bound to G1 and to G2›área 5 — which is why facts bind through a join
     * table instead of carrying a single group id.
     *
     * @return array{g1: ConvenioGroup, g2: ConvenioGroup, g3: ConvenioGroup, area5: ConvenioGroup, resto: ConvenioGroup}
     */
    private function navarra(): array
    {
        $g1 = $this->node('Grupo 1', '1');
        $g2 = $this->node('Grupo 2', '2');
        $g3 = $this->node('Grupo 3', '3');
        $area5 = $this->node('área 5', 'area-5', $g2);
        $resto = $this->node('resto áreas', 'resto-areas', $g2);

        $this->bind(
            $this->verifiedFact('90 días (indefinidos), 75 días (temporales > 3 meses), 60 días (hasta 3 meses)',
                'Grupo 1 (todas las áreas) y Grupo 2 (área 5)', null),
            $g1, $area5,
        );
        $this->bind(
            $this->verifiedFact('60 días (indefinidos), 45 días (temporales > 3 meses), 30 días (hasta 3 meses)',
                'Grupo 2 (resto áreas)', null),
            $resto,
        );
        $this->bind($this->verifiedFact('45 días (indefinidos), 30 días (temporales > 3 meses)',
            'Grupo 3 (todas las áreas)', null), $g3);

        return ['g1' => $g1, 'g2' => $g2, 'g3' => $g3, 'area5' => $area5, 'resto' => $resto];
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

            public function retrieve(array $params): array
            {
                // No governing convenio prose → Phase 2 composition falls through to
                // the Phase 1 quote (this test pins the pure quoted-value fallback).
                return ['chunks' => [], 'eligible_total' => 0];
            }

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
