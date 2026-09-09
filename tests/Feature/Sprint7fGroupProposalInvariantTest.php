<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AnswerModelSetting;
use App\Models\Convenio;
use App\Models\ConvenioGroup;
use App\Models\ConvenioGroupCategory;
use App\Models\ConvenioJobCategory;
use App\Models\Document;
use App\Models\DocumentPage;
use App\Models\DocumentType;
use App\Models\ReferenceFact;
use App\Models\ReferenceFactGroupScope;
use App\Models\Sector;
use App\Models\TagEvent;
use App\Models\Territory;
use App\Models\Topic;
use App\Services\ConvenioGroupProposalService;
use App\Services\ExtractionClient;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Sprint 7f (ADR-0028) Phase 2 acceptance proof — propose, then approve.
 *
 * The 7b-2 invariants, re-proven for STRUCTURE rather than facts, with a
 * STUBBED provider (no hr-ai, no LLM, no network) so the persist and approval
 * contracts are deterministic.
 *
 *  1. AI nodes land `ai_agent` / `needs_review`. Phase 3's matcher joins
 *     `status = 'approved'`, so a proposal is not merely unverified — it is
 *     invisible to the answer path.
 *  2. NO CATEGORY IS EVER MINTED (ADR-0011), and a foreign convenio's category
 *     id is dropped rather than mis-bound.
 *  3. Re-running is IDEMPOTENT on (convenio, parent, code), and it never
 *     reopens a node a human already approved or rejected.
 *  4. The agent never approves anything — there is no code path from `ai_agent`
 *     to `approved`.
 *  5. APPROVING A NODE WRITES NO BINDING. `reference_fact_group_scopes` rows
 *     appear only for the fact ids the reviewer confirms from the diff, and a
 *     confirmed id is re-checked server-side against the label grammar.
 *  6. Structure a human is relying on cannot be pulled out from under them: no
 *     renaming an approved node, no rejecting one with bindings, no approving a
 *     sub-area under a pending parent.
 *
 * The convenio modelled is Hostelería Navarra, whose verified fact 44 —
 * "Grupo 1 (todas las áreas) y Grupo 2 (área 5)" — is the one the live digit
 * matcher mis-scopes, and whose G2 the convenio really does price in two slices.
 */
class Sprint7fGroupProposalInvariantTest extends TestCase
{
    use RefreshDatabase;

    private Convenio $convenio;

    private Convenio $otherConvenio;

    private Topic $topic;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);

        $territory = Territory::create(['code' => '31', 'name' => 'Navarra', 'level' => 'provincial', 'aliases' => []]);
        $sector = Sector::create(['name' => 'Hostelería', 'aliases' => []]);
        $this->convenio = Convenio::create([
            'numero' => '31003805011981', 'name' => 'HOSTELERIA NAVARRA',
            'territory_id' => $territory->id, 'sector_id' => $sector->id,
        ]);
        $this->otherConvenio = Convenio::create([
            'numero' => '31003805011982', 'name' => 'OTRO CONVENIO',
            'territory_id' => $territory->id, 'sector_id' => $sector->id,
        ]);
        $this->topic = Topic::create(['name' => 'Periodo de prueba', 'status' => 'approved', 'aliases' => []]);

        // The provider key must be "configured" for propose() to proceed; the
        // stub never uses it (no network). current() resolves via id = 1, and
        // Postgres sequences are NOT rolled back between tests (and `id` isn't
        // mass-assignable), so pin id = 1 explicitly — the proven 7a/7b-2
        // pattern — otherwise current() creates a fresh UNCONFIGURED row and the
        // proposer skips.
        $setting = new AnswerModelSetting(['provider' => 'claude']);
        $setting->id = 1;
        $setting->save();
        $setting->setKey('sk-test-not-used');
        config(['services.hr_ai.answer_model' => 'claude-test']);
    }

    // ── 1. Inert on arrival ─────────────────────────────────────────────────

    public function test_every_proposed_node_lands_ai_agent_needs_review_and_nothing_is_approved(): void
    {
        $summary = $this->serviceReturning($this->navarraTree())->propose($this->convenio);

        $this->assertSame('ok', $summary['status']);
        $this->assertSame(5, $summary['created']);

        $nodes = ConvenioGroup::where('convenio_id', $this->convenio->id)->get();
        $this->assertCount(5, $nodes);

        foreach ($nodes as $node) {
            $this->assertSame(ConvenioGroup::SOURCE_AI, $node->source, $node->label);
            $this->assertSame(ConvenioGroup::STATUS_NEEDS_REVIEW, $node->status, $node->label);
            $this->assertNull($node->approved_by, $node->label);
            $this->assertNull($node->approved_at, $node->label);
            $this->assertNotNull($node->proposal_batch_id, $node->label);
        }

        // Invariant 4, stated as a query: the agent cannot produce approved rows.
        $this->assertSame(0, ConvenioGroup::where('status', ConvenioGroup::STATUS_APPROVED)->count());
    }

    public function test_the_printed_label_is_kept_and_the_comparison_key_is_derived_here(): void
    {
        // The model returns "Grupo 2" / "área 5"; `GroupCodeNormalizer` — the one
        // implementation — derives the key Phase 3 compares. The model never
        // produces a key, so there is nothing to keep in sync.
        $this->serviceReturning($this->navarraTree())->propose($this->convenio);

        $g2 = $this->findNode('2');
        $this->assertSame('Grupo 2', $g2->label);
        $this->assertSame('2', $g2->code_normalized);

        $area5 = ConvenioGroup::where('parent_id', $g2->id)->where('code_normalized', 'area-5')->first();
        $this->assertNotNull($area5);
        $this->assertSame('área 5', $area5->label);
    }

    public function test_a_roman_numeral_label_normalizes_onto_the_same_key_as_its_arabic_twin(): void
    {
        $this->serviceReturning([$this->node('Grupo III', excerpt: 'Grupo III: ...')])->propose($this->convenio);

        $this->assertSame('3', ConvenioGroup::where('convenio_id', $this->convenio->id)->first()->code_normalized);
    }

    public function test_a_label_with_no_derivable_key_is_refused_rather_than_invented(): void
    {
        // Normalizer rule 6: "Grupo" alone has no comparison key, and Phase 3
        // compares keys. Refusing costs a node; inventing one costs an answer.
        $summary = $this->serviceReturning([
            $this->node('Grupo', excerpt: 'Grupo'),
            $this->node('Grupo 1', excerpt: 'Grupo 1: seis meses'),
        ])->propose($this->convenio);

        $this->assertSame(1, $summary['created']);
        $this->assertSame(1, $summary['skipped_unnormalizable']);
        $this->assertSame(['1'], ConvenioGroup::where('convenio_id', $this->convenio->id)
            ->pluck('code_normalized')->all());
    }

    public function test_the_excerpt_and_the_uncertainty_flag_are_kept_together_on_the_node(): void
    {
        // The reviewer needs both in one place: the citation makes the node
        // checkable, the flag tells them to look harder.
        $this->serviceReturning([
            $this->node('Grupo I', excerpt: 'Grupo I: personal directivo', locator: 'p.4', uncertainty: [
                'field' => 'membership',
                'reason' => 'La tabla imprime el grupo una sola vez y deja el resto en blanco.',
            ]),
        ])->propose($this->convenio);

        $excerpt = (string) ConvenioGroup::where('convenio_id', $this->convenio->id)->first()->source_excerpt;
        $this->assertStringContainsString('[p.4]', $excerpt);
        $this->assertStringContainsString('personal directivo', $excerpt);
        $this->assertStringContainsString('⚠ membership', $excerpt);
        $this->assertStringContainsString('una sola vez', $excerpt);
    }

    public function test_the_proposal_is_recorded_in_the_append_only_audit(): void
    {
        $this->serviceReturning($this->navarraTree())->propose($this->convenio);

        $events = TagEvent::where('entity_type', 'convenio_group')->get();
        $this->assertCount(5, $events);
        foreach ($events as $event) {
            $this->assertSame('ai_agent', $event->source);
            $this->assertNull($event->actor_id);
            $this->assertSame(ConvenioGroup::STATUS_NEEDS_REVIEW, $event->new_value);
        }
    }

    // ── 2. Categories are a closed set ──────────────────────────────────────

    public function test_a_category_from_another_convenio_is_dropped_not_mis_bound(): void
    {
        $mine = ConvenioJobCategory::create([
            'convenio_id' => $this->convenio->id, 'name' => 'Camarero',
        ]);
        $foreign = ConvenioJobCategory::create([
            'convenio_id' => $this->otherConvenio->id, 'name' => 'Ajeno',
        ]);

        $summary = $this->serviceReturning([
            $this->node('Grupo 1', categories: [$mine->id, $foreign->id, 999999]),
        ])->propose($this->convenio);

        $this->assertSame(1, $summary['categories_proposed']);
        $this->assertSame(2, $summary['dropped_foreign_categories']);
        $this->assertSame(
            [$mine->id],
            ConvenioGroupCategory::pluck('job_category_id')->all(),
        );
    }

    public function test_a_proposed_category_membership_is_never_approved(): void
    {
        $category = ConvenioJobCategory::create(['convenio_id' => $this->convenio->id, 'name' => 'Camarero']);
        $this->serviceReturning([$this->node('Grupo 1', categories: [$category->id])])->propose($this->convenio);

        $membership = ConvenioGroupCategory::first();
        $this->assertSame(ConvenioGroupCategory::STATUS_NEEDS_REVIEW, $membership->status);
        $this->assertSame(ConvenioGroupCategory::SOURCE_AI, $membership->source);
        $this->assertNull($membership->approved_by);
    }

    public function test_the_proposer_never_creates_a_job_category(): void
    {
        $before = ConvenioJobCategory::count();
        $this->serviceReturning($this->navarraTree())->propose($this->convenio);
        $this->assertSame($before, ConvenioJobCategory::count());
    }

    // ── 3. Idempotence, and never reopening a decision ──────────────────────

    public function test_re_running_upserts_rather_than_stacking_duplicates(): void
    {
        $service = $this->serviceReturning($this->navarraTree());
        $service->propose($this->convenio);
        $second = $service->propose($this->convenio);

        $this->assertSame(0, $second['created']);
        $this->assertSame(5, $second['updated']);
        $this->assertSame(5, ConvenioGroup::where('convenio_id', $this->convenio->id)->count());
    }

    public function test_re_running_does_not_reopen_an_approved_node(): void
    {
        $this->serviceReturning($this->navarraTree())->propose($this->convenio);
        $g1 = $this->findNode('1');
        $this->approveDirectly($g1);

        $again = $this->serviceReturning($this->navarraTree())->propose($this->convenio);

        $this->assertSame(ConvenioGroup::STATUS_APPROVED, $g1->fresh()->status);
        $this->assertGreaterThanOrEqual(1, $again['skipped_locked']);
    }

    public function test_re_running_does_not_resurrect_a_rejected_node(): void
    {
        $this->serviceReturning($this->navarraTree())->propose($this->convenio);
        $this->findNode('3')->update(['status' => ConvenioGroup::STATUS_REJECTED]);

        $this->serviceReturning($this->navarraTree())->propose($this->convenio);

        $this->assertSame(ConvenioGroup::STATUS_REJECTED, $this->findNode('3')->status);
    }

    public function test_a_sub_area_whose_root_was_refused_is_dropped_not_re_parented(): void
    {
        // The root "Grupo" has no derivable key, so it is refused; its area must
        // not float up to become a top-level group of its own.
        $summary = $this->serviceReturning([
            $this->node('Grupo'),
            $this->node('área 5', parent: 'Grupo', excerpt: 'área 5: 90 días'),
        ])->propose($this->convenio);

        $this->assertSame(0, $summary['created']);
        $this->assertSame(0, ConvenioGroup::count());
    }

    public function test_a_provider_failure_leaves_the_convenio_with_no_structure(): void
    {
        // The safe state: Phase 3 joins approved nodes only, so "no proposal"
        // changes nothing about how this convenio answers today.
        $fake = new class extends ExtractionClient
        {
            public function __construct() {}

            public function proposeGroups(array $convenio, string $pagesText, array $observedGroupLabels, string $decryptedKey, array $providerConfig): array
            {
                return ['groups' => [], 'error' => 'propose_groups_unavailable'];
            }
        };

        $this->withDocumentText();
        $summary = (new ConvenioGroupProposalService($fake))->propose($this->convenio);

        $this->assertSame('error', $summary['status']);
        $this->assertSame(0, ConvenioGroup::count());
    }

    // ── 5. Approval writes no binding by itself ─────────────────────────────

    public function test_the_binding_diff_writes_nothing_and_shows_the_facts_that_would_bind(): void
    {
        $this->serviceReturning($this->navarraTree())->propose($this->convenio);
        $g1 = $this->findNode('1');
        $g2 = $this->findNode('2');
        $this->approveDirectly($g2);
        $area5 = ConvenioGroup::where('parent_id', $g2->id)->where('code_normalized', 'area-5')->first();
        $this->approveDirectly($area5);

        $fact = $this->fact('Grupo 1 (todas las áreas) y Grupo 2 (área 5)', '90 días');

        $response = $this->getAs($this->admin(), "/admin/convenio-groups/{$g1->id}/binding-diff");
        $response->assertStatus(200);

        $would = $response->json('would_bind');
        $this->assertCount(1, $would);
        $this->assertSame($fact->id, $would[0]['fact_id']);
        $this->assertSame('compound', $would[0]['kind']);
        $this->assertFalse($would[0]['already_bound']);
        // It says out loud that this fact ALSO lands on área 5 — the reviewer
        // must see the whole scope of a compound fact, not just this node's share.
        $this->assertSame([$area5->id], $would[0]['also_binds_to_node_ids']);

        // A read is a read.
        $this->assertSame(0, ReferenceFactGroupScope::count());
        $this->assertSame(ConvenioGroup::STATUS_NEEDS_REVIEW, $g1->fresh()->status);
    }

    public function test_approving_with_no_confirmed_facts_approves_the_node_and_binds_nothing(): void
    {
        // The load-bearing test of the sprint's whole posture. Approval makes the
        // node real; it does not wire anything to it.
        $this->serviceReturning($this->navarraTree())->propose($this->convenio);
        $g1 = $this->findNode('1');
        $this->fact('Grupo 1 (todas las áreas)', '90 días');

        $response = $this->postAs($this->admin(), "/admin/convenio-groups/{$g1->id}/approve", [
            'confirmed_fact_ids' => [],
        ]);
        $response->assertStatus(200);

        $this->assertSame(ConvenioGroup::STATUS_APPROVED, $g1->fresh()->status);
        $this->assertNotNull($g1->fresh()->approved_by);
        $this->assertSame(0, ReferenceFactGroupScope::count());
    }

    public function test_a_confirmed_fact_binds_with_provenance(): void
    {
        $this->serviceReturning($this->navarraTree())->propose($this->convenio);
        $g1 = $this->findNode('1');
        $fact = $this->fact('Grupo 1 (todas las áreas)', '90 días');
        $admin = $this->admin();

        $this->postAs($admin, "/admin/convenio-groups/{$g1->id}/approve", [
            'confirmed_fact_ids' => [$fact->id],
        ])->assertStatus(200);

        $binding = ReferenceFactGroupScope::where('convenio_group_id', $g1->id)->first();
        $this->assertNotNull($binding);
        $this->assertSame($fact->id, $binding->reference_fact_id);
        $this->assertSame($admin->id, $binding->bound_by);
        $this->assertNotNull($binding->bound_at);

        $event = TagEvent::where('facet', 'group_scope')->first();
        $this->assertSame('admin_manual', $event->source);
        $this->assertSame($admin->id, $event->actor_id);
        $this->assertSame('convenio_group:'.$g1->id, $event->new_value);
    }

    public function test_a_confirmed_fact_that_does_not_resolve_to_the_node_is_rejected(): void
    {
        // A stale or hand-edited payload must not be able to bind a fact whose
        // label points somewhere else — the reviewer's ids authorize, they do
        // not override the grammar.
        $this->serviceReturning($this->navarraTree())->propose($this->convenio);
        $g1 = $this->findNode('1');
        $elsewhere = $this->fact('Grupo 3 (todas las áreas)', '45 días');

        $this->postAs($this->admin(), "/admin/convenio-groups/{$g1->id}/approve", [
            'confirmed_fact_ids' => [$elsewhere->id],
        ])->assertStatus(422);

        $this->assertSame(0, ReferenceFactGroupScope::count());
        // The whole approval rolled back — the node is not half-approved.
        $this->assertSame(ConvenioGroup::STATUS_NEEDS_REVIEW, $g1->fresh()->status);
    }

    public function test_a_complement_label_is_never_bindable_even_if_confirmed(): void
    {
        $this->serviceReturning($this->navarraTree())->propose($this->convenio);
        $g2 = $this->findNode('2');
        $resto = $this->fact('Resto de grupos', '2 meses');

        $this->postAs($this->admin(), "/admin/convenio-groups/{$g2->id}/approve", [
            'confirmed_fact_ids' => [$resto->id],
        ])->assertStatus(422);

        $this->assertSame(0, ReferenceFactGroupScope::count());
    }

    public function test_the_diff_lists_a_complement_label_as_needing_manual_binding(): void
    {
        $this->serviceReturning($this->navarraTree())->propose($this->convenio);
        $g2 = $this->findNode('2');
        $this->fact('Resto de grupos', '2 meses');

        $manual = $this->getAs($this->admin(), "/admin/convenio-groups/{$g2->id}/binding-diff")
            ->json('needs_manual_binding');

        $this->assertCount(1, $manual);
        $this->assertSame('complement', $manual[0]['kind']);
        $this->assertNotNull($manual[0]['reason']);
    }

    // ── 5b. Binding after approval, and the human-authority lane ────────────

    /**
     * Found at Checkpoint 2, by Pedram approving the real tree: approval was the
     * only moment a binding could be created, so approving a node with a fact
     * unticked left no way back to it. Binding is a separate decision from
     * approval and needs its own door.
     */
    public function test_a_fact_can_be_bound_after_the_node_was_already_approved(): void
    {
        $this->serviceReturning($this->navarraTree())->propose($this->convenio);
        $g1 = $this->findNode('1');
        $fact = $this->fact('Grupo 1 (todas las áreas)', '90 días');

        // Approved with nothing ticked — the exact situation that was a dead end.
        $this->postAs($this->admin(), "/admin/convenio-groups/{$g1->id}/approve", [
            'confirmed_fact_ids' => [],
        ])->assertStatus(200);
        $this->assertSame(0, ReferenceFactGroupScope::count());

        $this->postAs($this->admin(), "/admin/convenio-groups/{$g1->id}/bind", [
            'fact_ids' => [$fact->id],
        ])->assertStatus(200);

        $this->assertSame(1, ReferenceFactGroupScope::where('convenio_group_id', $g1->id)->count());
    }

    public function test_binding_twice_is_idempotent_and_logs_once(): void
    {
        $this->serviceReturning($this->navarraTree())->propose($this->convenio);
        $g1 = $this->findNode('1');
        $this->approveDirectly($g1);
        $fact = $this->fact('Grupo 1 (todas las áreas)', '90 días');

        foreach ([1, 2] as $_) {
            $this->postAs($this->admin(), "/admin/convenio-groups/{$g1->id}/bind", [
                'fact_ids' => [$fact->id],
            ])->assertStatus(200);
        }

        $this->assertSame(1, ReferenceFactGroupScope::count());
        $this->assertSame(1, TagEvent::where('facet', 'group_scope')->count());
    }

    public function test_a_compound_fact_binds_to_both_of_its_nodes_independently(): void
    {
        // Fact 44's real shape. Binding it to Grupo 1 and to Grupo 2 › área 5
        // are two separate acts, and the join table has to hold both — a fact
        // bound to only half its scope answers confidently for one group and
        // silently omits the other.
        $this->serviceReturning($this->navarraTree())->propose($this->convenio);
        $g1 = $this->findNode('1');
        $g2 = $this->findNode('2');
        $this->approveDirectly($g1);
        $this->approveDirectly($g2);
        $area5 = ConvenioGroup::where('parent_id', $g2->id)->where('code_normalized', 'area-5')->first();
        $this->approveDirectly($area5);

        $fact = $this->fact('Grupo 1 (todas las áreas) y Grupo 2 (área 5)', '90 días');

        foreach ([$g1->id, $area5->id] as $nodeId) {
            $this->postAs($this->admin(), "/admin/convenio-groups/{$nodeId}/bind", [
                'fact_ids' => [$fact->id],
            ])->assertStatus(200);
        }

        $this->assertEqualsCanonicalizing(
            [$g1->id, $area5->id],
            ReferenceFactGroupScope::where('reference_fact_id', $fact->id)
                ->pluck('convenio_group_id')->all(),
        );
    }

    public function test_a_label_the_planner_refuses_needs_an_explicit_human_override(): void
    {
        // "Grupo 2 excepto área cinco" DOES mean resto áreas — but only because
        // someone read the convenio. The planner declining to infer that is
        // correct; a human asserting it is also correct. The one thing that must
        // not happen is the machine inferring it silently.
        $this->serviceReturning($this->navarraTree())->propose($this->convenio);
        $g2 = $this->findNode('2');
        $this->approveDirectly($g2);
        $resto = ConvenioGroup::where('parent_id', $g2->id)->where('code_normalized', 'resto-areas')->first();
        $this->approveDirectly($resto);

        $fact = $this->fact('Grupo 2 excepto área cinco', '60 días');

        // Without the flag it is refused, and the message points at the lane.
        $this->postAs($this->admin(), "/admin/convenio-groups/{$resto->id}/bind", [
            'fact_ids' => [$fact->id],
        ])->assertStatus(422);
        $this->assertSame(0, ReferenceFactGroupScope::count());

        $this->postAs($this->admin(), "/admin/convenio-groups/{$resto->id}/bind", [
            'fact_ids' => [$fact->id], 'override' => true, 'note' => 'Art. 19: el resto del grupo 2.',
        ])->assertStatus(200);

        $this->assertSame(1, ReferenceFactGroupScope::where('convenio_group_id', $resto->id)->count());
    }

    public function test_an_overridden_binding_records_that_it_was_asserted_not_read(): void
    {
        // The audit has to distinguish a scope a human ASSERTED from one the
        // grammar READ, or a later reader cannot tell which decisions to trust.
        $this->serviceReturning($this->navarraTree())->propose($this->convenio);
        $g2 = $this->findNode('2');
        $this->approveDirectly($g2);
        $resto = ConvenioGroup::where('parent_id', $g2->id)->where('code_normalized', 'resto-areas')->first();
        $this->approveDirectly($resto);
        $fact = $this->fact('Grupo 2 excepto área cinco', '60 días');

        $this->postAs($this->admin(), "/admin/convenio-groups/{$resto->id}/bind", [
            'fact_ids' => [$fact->id], 'override' => true, 'note' => 'Art. 19.',
        ])->assertStatus(200);

        $event = TagEvent::where('facet', 'group_scope_manual')->firstOrFail();
        $this->assertSame('admin_manual', $event->source);
        $this->assertSame($this->admin()->id, $event->actor_id);
        $this->assertStringContainsString('NO resuelve esta etiqueta', (string) $event->note);
        $this->assertStringContainsString('complement', (string) $event->note);
        $this->assertStringContainsString('Art. 19.', (string) $event->note);
    }

    public function test_override_cannot_bind_a_fact_whose_label_resolves_somewhere_else(): void
    {
        // Not a judgement call — a contradiction. The fix is to correct the
        // fact's label, not to bind past it, so override is not a way to
        // bypass the grammar wholesale.
        $this->serviceReturning($this->navarraTree())->propose($this->convenio);
        $g1 = $this->findNode('1');
        $g3 = $this->findNode('3');
        $this->approveDirectly($g1);
        $this->approveDirectly($g3);
        $fact = $this->fact('Grupo 3 (todas las áreas)', '45 días');

        $this->postAs($this->admin(), "/admin/convenio-groups/{$g1->id}/bind", [
            'fact_ids' => [$fact->id], 'override' => true,
        ])->assertStatus(422);

        $this->assertSame(0, ReferenceFactGroupScope::count());
    }

    public function test_override_cannot_narrow_a_convenio_wide_fact(): void
    {
        // A convenio-wide fact already answers, at Tier 3. Giving it a group
        // would restrict a rule that applies to the whole workforce — the
        // opposite of the bug this sprint fixes, and just as wrong.
        $this->serviceReturning($this->navarraTree())->propose($this->convenio);
        $g1 = $this->findNode('1');
        $this->approveDirectly($g1);
        $fact = $this->fact(null, 'dos meses para toda la plantilla');

        $this->postAs($this->admin(), "/admin/convenio-groups/{$g1->id}/bind", [
            'fact_ids' => [$fact->id], 'override' => true,
        ])->assertStatus(422);

        $this->assertSame(0, ReferenceFactGroupScope::count());
    }

    public function test_nothing_can_be_bound_to_a_pending_node(): void
    {
        $this->serviceReturning($this->navarraTree())->propose($this->convenio);
        $g1 = $this->findNode('1');
        $fact = $this->fact('Grupo 1 (todas las áreas)', '90 días');

        $this->postAs($this->admin(), "/admin/convenio-groups/{$g1->id}/bind", [
            'fact_ids' => [$fact->id],
        ])->assertStatus(422);

        $this->assertSame(0, ReferenceFactGroupScope::count());
    }

    public function test_a_fact_from_another_convenio_cannot_be_bound(): void
    {
        $this->serviceReturning($this->navarraTree())->propose($this->convenio);
        $g1 = $this->findNode('1');
        $this->approveDirectly($g1);

        $foreign = ReferenceFact::create([
            'uuid' => (string) \Str::uuid(),
            'convenio_id' => $this->otherConvenio->id,
            'topic_id' => $this->topic->id,
            'group_label' => 'Grupo 1',
            'value' => 'otra cosa',
            'authority_level' => ReferenceFact::AUTHORITY_LEVEL,
            'source' => 'admin_manual',
            'status' => 'verified',
            'validity_start' => '2026-01-01',
        ]);

        $this->postAs($this->admin(), "/admin/convenio-groups/{$g1->id}/bind", [
            'fact_ids' => [$foreign->id], 'override' => true,
        ])->assertStatus(422);

        $this->assertSame(0, ReferenceFactGroupScope::count());
    }

    public function test_the_tree_read_offers_the_approved_nodes_for_manual_binding(): void
    {
        // The picker on the unbound list needs a path label: a bare "resto
        // áreas" could belong to any group.
        $this->serviceReturning($this->navarraTree())->propose($this->convenio);
        $g2 = $this->findNode('2');
        $this->approveDirectly($g2);
        $resto = ConvenioGroup::where('parent_id', $g2->id)->where('code_normalized', 'resto-areas')->first();
        $this->approveDirectly($resto);

        $nodes = $this->getAs($this->admin(), '/admin/convenio-groups/convenio/'.$this->convenio->id)
            ->assertStatus(200)
            ->json('approved_nodes');

        $this->assertSame(['Grupo 2', 'Grupo 2 › resto áreas'], array_column($nodes, 'path_label'));
    }

    // ── 6. Structure in use cannot be pulled away ───────────────────────────

    public function test_a_sub_area_cannot_be_approved_before_its_parent(): void
    {
        $this->serviceReturning($this->navarraTree())->propose($this->convenio);
        $g2 = $this->findNode('2');
        $area5 = ConvenioGroup::where('parent_id', $g2->id)->where('code_normalized', 'area-5')->first();

        $this->postAs($this->admin(), "/admin/convenio-groups/{$area5->id}/approve", [
            'confirmed_fact_ids' => [],
        ])->assertStatus(422);

        $this->assertSame(ConvenioGroup::STATUS_NEEDS_REVIEW, $area5->fresh()->status);
    }

    public function test_an_approved_node_cannot_be_renamed(): void
    {
        // Renaming would change the key Phase 3 compares, under facts and
        // employees already attached to it.
        $this->serviceReturning($this->navarraTree())->propose($this->convenio);
        $g1 = $this->findNode('1');
        $this->approveDirectly($g1);

        $this->patchAs($this->admin(), "/admin/convenio-groups/{$g1->id}", ['label' => 'Grupo 9'])
            ->assertStatus(422);

        $this->assertSame('1', $g1->fresh()->code_normalized);
    }

    public function test_a_node_with_bindings_cannot_be_rejected(): void
    {
        $this->serviceReturning($this->navarraTree())->propose($this->convenio);
        $g1 = $this->findNode('1');
        $fact = $this->fact('Grupo 1 (todas las áreas)', '90 días');
        $this->postAs($this->admin(), "/admin/convenio-groups/{$g1->id}/approve", [
            'confirmed_fact_ids' => [$fact->id],
        ])->assertStatus(200);

        $this->postAs($this->admin(), "/admin/convenio-groups/{$g1->id}/reject", [])->assertStatus(422);
        $this->assertSame(ConvenioGroup::STATUS_APPROVED, $g1->fresh()->status);

        // Unbind first, deliberately, and then it can go.
        $this->deleteAs($this->admin(), "/admin/convenio-groups/{$g1->id}/bindings/{$fact->id}")
            ->assertStatus(200);
        $this->postAs($this->admin(), "/admin/convenio-groups/{$g1->id}/reject", [])->assertStatus(200);
        $this->assertSame(ConvenioGroup::STATUS_REJECTED, $this->findNode('1')->status);
    }

    public function test_editing_a_pending_node_re_derives_the_key_and_takes_ownership(): void
    {
        $this->serviceReturning($this->navarraTree())->propose($this->convenio);
        $g1 = $this->findNode('1');

        $this->patchAs($this->admin(), "/admin/convenio-groups/{$g1->id}", ['label' => 'Grupo IV'])
            ->assertStatus(200)
            ->assertJsonPath('group.code_normalized', '4');

        // A human edit means re-running the proposer will not overwrite it.
        $this->assertSame(ConvenioGroup::SOURCE_MANUAL, $g1->fresh()->source);
    }

    public function test_an_edit_to_an_unnormalizable_label_is_rejected(): void
    {
        $this->serviceReturning($this->navarraTree())->propose($this->convenio);
        $g1 = $this->findNode('1');

        $this->patchAs($this->admin(), "/admin/convenio-groups/{$g1->id}", ['label' => 'Grupo'])
            ->assertStatus(422);

        $this->assertSame('1', $g1->fresh()->code_normalized);
    }

    public function test_a_category_cannot_be_approved_onto_two_groups(): void
    {
        // The Phase 1 partial unique index surfaced as a 422 that names the
        // node already claiming it, rather than a 500.
        $category = ConvenioJobCategory::create(['convenio_id' => $this->convenio->id, 'name' => 'Camarero']);
        $this->serviceReturning([
            $this->node('Grupo 1', categories: [$category->id]),
            $this->node('Grupo 2', categories: [$category->id]),
        ])->propose($this->convenio);

        $g1 = $this->findNode('1');
        $g2 = $this->findNode('2');

        $this->postAs($this->admin(), "/admin/convenio-groups/{$g1->id}/approve", [
            'confirmed_fact_ids' => [], 'confirmed_category_ids' => [$category->id],
        ])->assertStatus(200);

        $this->postAs($this->admin(), "/admin/convenio-groups/{$g2->id}/approve", [
            'confirmed_fact_ids' => [], 'confirmed_category_ids' => [$category->id],
        ])->assertStatus(422);

        $this->assertSame(1, ConvenioGroupCategory::where('status', ConvenioGroupCategory::STATUS_APPROVED)->count());
    }

    public function test_an_uncategorised_category_id_can_never_be_approved_onto_a_node(): void
    {
        // Approval can only confirm a membership that was PROPOSED. There is no
        // path here that creates one.
        $category = ConvenioJobCategory::create(['convenio_id' => $this->convenio->id, 'name' => 'Camarero']);
        $this->serviceReturning([$this->node('Grupo 1')])->propose($this->convenio);
        $g1 = $this->findNode('1');

        $this->postAs($this->admin(), "/admin/convenio-groups/{$g1->id}/approve", [
            'confirmed_fact_ids' => [], 'confirmed_category_ids' => [$category->id],
        ])->assertStatus(422);

        $this->assertSame(0, ConvenioGroupCategory::count());
    }

    // ── The tree read ───────────────────────────────────────────────────────

    public function test_the_tree_read_nests_sub_areas_and_reports_the_normalization_rule(): void
    {
        $this->serviceReturning($this->navarraTree())->propose($this->convenio);

        $tree = $this->getAs($this->admin(), '/admin/convenio-groups/convenio/'.$this->convenio->id)
            ->assertStatus(200)
            ->json('tree');

        $this->assertSame(['Grupo 1', 'Grupo 2', 'Grupo 3'], array_column($tree, 'label'));
        $this->assertSame(['área 5', 'resto áreas'], array_column($tree[1]['children'], 'label'));
        // The key a node will be compared by is visible BEFORE it is approved.
        $this->assertSame('numeric_verbatim', $tree[0]['normalization_rule']);
        $this->assertSame('slug', $tree[1]['children'][0]['normalization_rule']);
    }

    public function test_the_tree_read_lists_facts_it_cannot_bind_so_they_are_not_silently_lost(): void
    {
        $this->serviceReturning($this->navarraTree())->propose($this->convenio);
        $this->approveDirectly($this->findNode('1'));
        $this->fact('Contratos de formación en alternancia', 'sin periodo de prueba');

        $unbindable = $this->getAs($this->admin(), '/admin/convenio-groups/convenio/'.$this->convenio->id)
            ->json('unbindable_facts');

        $this->assertCount(1, $unbindable);
        $this->assertSame('not_a_group', $unbindable[0]['status']);
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    /**
     * The service wired to a STUBBED ExtractionClient returning the given group
     * envelope — no hr-ai, no LLM, no network.
     *
     * @param  list<array<string,mixed>>  $groups
     */
    private function serviceReturning(array $groups): ConvenioGroupProposalService
    {
        $this->withDocumentText();

        $fake = new class($groups) extends ExtractionClient
        {
            /** @param list<array<string,mixed>> $groups */
            public function __construct(private array $groups) {}

            public function proposeGroups(
                array $convenio,
                string $pagesText,
                array $observedGroupLabels,
                string $decryptedKey,
                array $providerConfig,
            ): array {
                return ['groups' => $this->groups, 'trace_fragment' => ['stub' => true]];
            }
        };

        return new ConvenioGroupProposalService($fake);
    }

    /**
     * Hostelería Navarra's real shape: G2 priced in two slices, G1 and G3 whole.
     *
     * @return list<array<string,mixed>>
     */
    private function navarraTree(): array
    {
        return [
            $this->node('Grupo 1', excerpt: 'Grupo 1: 90 días', locator: 'p.12'),
            $this->node('Grupo 2', excerpt: 'Grupo 2: ...', locator: 'p.12'),
            $this->node('área 5', parent: 'Grupo 2', excerpt: 'área 5: 90 días', locator: 'p.12'),
            $this->node('resto áreas', parent: 'Grupo 2', excerpt: 'resto de áreas: 60 días', locator: 'p.12'),
            $this->node('Grupo 3', excerpt: 'Grupo 3: 45 días', locator: 'p.12'),
        ];
    }

    /**
     * @param  list<int>  $categories
     * @param  array<string,string>|null  $uncertainty
     * @return array<string,mixed>
     */
    private function node(
        string $label,
        ?string $parent = null,
        array $categories = [],
        ?string $excerpt = null,
        ?string $locator = null,
        ?array $uncertainty = null,
    ): array {
        return [
            'code_label' => $label,
            'parent_code_label' => $parent,
            'job_category_ids' => $categories,
            'source_excerpt' => $excerpt,
            'source_locator' => $locator,
            'confidence' => 0.9,
            'uncertainty' => $uncertainty,
        ];
    }

    /** The service skips a convenio with no text, so give it one page. */
    private function withDocumentText(): void
    {
        if (Document::where('convenio_id', $this->convenio->id)->exists()) {
            return;
        }

        $type = DocumentType::firstOrCreate(['code' => 'official_convenio'], ['name' => 'Convenio oficial']);

        $doc = Document::create([
            'title' => 'Convenio Hostelería Navarra',
            'source_filename' => 'hosteleria.pdf',
            'storage_path' => 'documents/test/hosteleria.pdf',
            'content_hash' => bin2hex(random_bytes(16)),
            'document_type_id' => $type->id,
            'convenio_id' => $this->convenio->id,
            'language' => 'es',
            'retrieval_status' => 'active',
            'authority_level' => 'official_convenio',
            'tagging_status' => 'under_review',
        ]);
        DocumentPage::create([
            'document_id' => $doc->id,
            'page_number' => 1,
            'text' => 'Artículo 12. Periodo de prueba. Grupo 1 y área 5 del Grupo 2: 90 días. '
                .'Grupo 2 resto de áreas: 60 días. Grupo 3: 45 días.',
        ]);
    }

    private function fact(?string $groupLabel, string $value): ReferenceFact
    {
        return ReferenceFact::create([
            'uuid' => (string) \Str::uuid(),
            'convenio_id' => $this->convenio->id,
            'topic_id' => $this->topic->id,
            'group_label' => $groupLabel,
            'value' => $value,
            'authority_level' => ReferenceFact::AUTHORITY_LEVEL,
            'source' => 'admin_manual',
            'status' => 'verified',
            'validity_start' => '2026-01-01',
        ]);
    }

    private function findNode(string $code): ConvenioGroup
    {
        return ConvenioGroup::where('convenio_id', $this->convenio->id)
            ->whereNull('parent_id')
            ->where('code_normalized', $code)
            ->firstOrFail();
    }

    /** Approve a node without going through the endpoint, to set up a fixture. */
    private function approveDirectly(ConvenioGroup $node): void
    {
        $node->update([
            'status' => ConvenioGroup::STATUS_APPROVED,
            'approved_by' => $this->admin()->id,
            'approved_at' => now(),
        ]);
    }

    private function admin(): Admin
    {
        static $admin = null;
        if ($admin === null || $admin->fresh() === null) {
            $admin = Admin::create([
                'email' => 'super-7f-p2@example.com', 'full_name' => 'Super 7f P2', 'status' => 'active',
            ]);
            $admin->assignRole('super_admin');
        }

        return $admin;
    }

    /** @return array<string,string> */
    private function auth(Admin $admin): array
    {
        return ['Authorization' => 'Bearer '.$admin->createToken('test')->plainTextToken, 'Accept' => 'application/json'];
    }

    private function resetPermCache(): void
    {
        $this->app['auth']->forgetGuards();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function getAs(Admin $admin, string $url): TestResponse
    {
        $this->resetPermCache();

        return $this->getJson($url, $this->auth($admin));
    }

    /** @param array<string,mixed> $payload */
    private function postAs(Admin $admin, string $url, array $payload): TestResponse
    {
        $this->resetPermCache();

        return $this->postJson($url, $payload, $this->auth($admin));
    }

    /** @param array<string,mixed> $payload */
    private function patchAs(Admin $admin, string $url, array $payload): TestResponse
    {
        $this->resetPermCache();

        return $this->patchJson($url, $payload, $this->auth($admin));
    }

    private function deleteAs(Admin $admin, string $url): TestResponse
    {
        $this->resetPermCache();

        return $this->deleteJson($url, [], $this->auth($admin));
    }
}
