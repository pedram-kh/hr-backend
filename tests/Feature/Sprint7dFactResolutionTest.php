<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Convenio;
use App\Models\ReferenceFact;
use App\Models\Sector;
use App\Models\TagEvent;
use App\Models\Territory;
use App\Models\Topic;
use App\Services\ReferenceFactAnswerService;
use App\Support\GroupLabel;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sprint 7d (ADR-0024), part B — fact version resolution.
 *
 * THE GOLD CASE is the pair 7b-2 documented as a miss (`sprint-07b-2/review.md`
 * "Version trap ⚠ PARTIAL"), reproduced here with the real strings:
 *
 *   file 1 (pre-2026): Navarra · Acción e Intervención Social, "Grupos 1 y 2" = Seis meses
 *   file 2 (2026):     same convenio + topic,                  "Grupo 2"      = Cuatro meses
 *
 * 7b-2's detector keys on the exact `group_label`, and `"grupos 1 y 2" !==
 * "grupo 2"`, so the pair slipped through. What this class proves:
 *
 *   1. the deterministic group-digit-token pass FLAGS the pair ({1,2} ∩ {2} ≠ ∅);
 *   2. `supersede` closes the older fact's validity window and DELETES NOTHING —
 *      the old value stays `verified` and answerable for its own window;
 *   3. a question dated inside the old window still resolves to the OLD value,
 *      and a present-day question resolves to the new one, THROUGH THE UNCHANGED
 *      7c answer rule — resolution reaches chat via corrected data only;
 *   4. the direction of a supersede is never guessed (422 when the dates don't
 *      support the claim);
 *   5. `coexist` and `reject` change no validity and delete nothing either.
 */
class Sprint7dFactResolutionTest extends TestCase
{
    use RefreshDatabase;

    private Convenio $convenio;

    private Topic $periodo;

    private Admin $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);

        $territory = Territory::create(['code' => '31', 'name' => 'Navarra', 'level' => 'provincial', 'aliases' => []]);
        $sector = Sector::create(['name' => 'Acción e Intervención Social', 'aliases' => []]);
        $this->convenio = Convenio::create([
            'numero' => '31101815012021', 'name' => 'Navarra Acción e Intervención Social',
            'territory_id' => $territory->id, 'sector_id' => $sector->id,
        ]);
        // A migration already seeds this topic (2026_06_26_120003) — bind, don't create
        // (vocabulary is bind-only, ADR-0011).
        $this->periodo = Topic::firstOrCreate(['name' => 'periodo de prueba'], ['status' => 'approved']);

        $this->admin = Admin::create(['email' => 'kc7d@example.com', 'full_name' => 'KC 7d', 'status' => 'active']);
        $this->admin->assignRole('super_admin');
    }

    private function fact(string $group, string $value, ?string $validityStart, ?string $validityEnd = null, string $status = 'verified'): ReferenceFact
    {
        return ReferenceFact::create([
            'convenio_id' => $this->convenio->id,
            'topic_id' => $this->periodo->id,
            'group_label' => $group,
            'value' => $value,
            'validity_start' => $validityStart,
            'validity_end' => $validityEnd,
            'authority_level' => ReferenceFact::AUTHORITY_LEVEL,
            'source' => 'ai_agent',
            'status' => $status,
        ]);
    }

    /** @return array<string,string> */
    private function auth(): array
    {
        $this->app['auth']->forgetGuards();
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        return ['Authorization' => 'Bearer '.$this->admin->createToken('t')->plainTextToken, 'Accept' => 'application/json'];
    }

    // ---- 1. The tokeniser, on the real strings --------------------------------

    public function test_group_token_overlap_relates_the_real_navarra_labels(): void
    {
        // The documented miss: an exact-label comparison says "different group".
        $this->assertNotSame(GroupLabel::normalize('Grupos 1 y 2'), GroupLabel::normalize('Grupo 2'));

        // The digit sets say otherwise — which is the whole fix.
        $this->assertSame([1, 2], GroupLabel::digits('Grupos 1 y 2'));
        $this->assertSame([2], GroupLabel::digits('Grupo 2'));
        $this->assertSame(GroupLabel::OVERLAP, GroupLabel::relate('Grupos 1 y 2', 'Grupo 2'));

        // And the pass stays precise: genuinely different groups are NOT a version,
        // and a label with no digits is skipped rather than guessed at.
        $this->assertSame(GroupLabel::DISJOINT, GroupLabel::relate('Grupos 1 y 2', 'Grupos 3,4 y 5'));
        $this->assertSame(GroupLabel::EXACT, GroupLabel::relate('Grupo 2', 'grupo  2'));
        $this->assertSame(GroupLabel::UNKNOWN, GroupLabel::relate('Personal titulado', 'Grupo 1'));
    }

    // ---- 2. The gold pair is surfaced as a FLAG --------------------------------

    public function test_the_navarra_version_pair_is_surfaced_by_the_token_overlap_pass(): void
    {
        $older = $this->fact('Grupos 1 y 2', 'Seis meses', '2021-01-01');
        $newer = $this->fact('Grupo 2', 'Cuatro meses', '2026-01-01');

        // Precondition: 7b-2's exact-key detector left this pair unflagged.
        $this->assertNull($newer->duplicate_of_id);

        $this->artisan('facts:scan-duplicates')->assertExitCode(0);

        $newer->refresh();
        $this->assertSame($older->id, $newer->duplicate_of_id, 'The re-split version pair must be flagged.');
        $this->assertSame('version', $newer->uncertainty['field']);
        $this->assertStringContainsString('grupos solapados', $newer->uncertainty['reason']);

        // The flag is a machine inference, marked as one, and the note names the pass
        // so the two detectors stay distinguishable in the audit trail.
        $event = TagEvent::where('entity_type', 'reference_fact')->where('facet', 'duplicate')->sole();
        $this->assertSame('ai_agent', $event->source);
        $this->assertStringContainsString('group-token overlap', $event->note);

        // FLAG ONLY: nothing resolved, no validity touched, nothing deleted.
        $this->assertNull($newer->resolution);
        $this->assertNull($newer->fresh()->validity_end);
        $this->assertNull($older->fresh()->validity_end);
        $this->assertSame(2, ReferenceFact::count());
    }

    public function test_the_pass_is_idempotent_and_never_reflags_a_resolved_pair(): void
    {
        $older = $this->fact('Grupos 1 y 2', 'Seis meses', '2021-01-01');
        $newer = $this->fact('Grupo 2', 'Cuatro meses', '2026-01-01');

        $this->artisan('facts:scan-duplicates')->assertExitCode(0);
        $this->artisan('facts:scan-duplicates')->assertExitCode(0);
        $this->assertSame(1, TagEvent::where('facet', 'duplicate')->count(), 'Re-running must not duplicate the flag.');

        // Resolve it, clear the flag pointer's "unresolved" state, then re-scan.
        $this->postJson("/admin/reference-facts/{$newer->uuid}/resolve-duplicate",
            ['action' => 'supersede', 'newer_uuid' => $newer->uuid], $this->auth())->assertStatus(200);

        $this->artisan('facts:scan-duplicates')->assertExitCode(0);
        $this->assertSame(1, TagEvent::where('facet', 'duplicate')->count(),
            'A pair a human resolved must never be re-flagged.');
        $this->assertSame('supersedes', $newer->fresh()->resolution);
        $this->assertNotNull($older->fresh()->validity_end);
    }

    // ---- 3. Supersede closes validity and deletes nothing ---------------------

    public function test_supersede_closes_the_older_window_keeps_both_and_deletes_nothing(): void
    {
        $older = $this->fact('Grupos 1 y 2', 'Seis meses', '2021-01-01');
        $newer = $this->fact('Grupo 2', 'Cuatro meses', '2026-01-01');
        $newer->update(['duplicate_of_id' => $older->id]);

        $this->postJson("/admin/reference-facts/{$newer->uuid}/resolve-duplicate", [
            'action' => 'supersede', 'newer_uuid' => $newer->uuid,
            'note' => 'file 2 re-splits G2 to 4 meses from 2026',
        ], $this->auth())->assertStatus(200)->assertJson([
            'action' => 'supersede',
            'older_validity_end' => '2025-12-31', // the day before the newer starts
            'older_status' => 'verified',          // NOT demoted
            'deleted' => 0,
        ]);

        $older->refresh();
        $newer->refresh();

        $this->assertSame('2025-12-31', $older->validity_end->toDateString());
        $this->assertSame('Seis meses', $older->value, 'The old VALUE is never rewritten.');
        $this->assertSame('verified', $older->status);
        $this->assertSame('superseded', $older->resolution);
        $this->assertSame($newer->id, $older->superseded_by_id);
        $this->assertSame('supersedes', $newer->resolution);
        $this->assertSame($older->id, $newer->duplicate_of_id, 'The link is retained as lineage, not erased.');
        $this->assertSame(2, ReferenceFact::count(), 'Both rows survive — a supersede is not a delete.');

        // The narrative is append-only in tag_events, on both sides.
        $this->assertSame(1, TagEvent::where('entity_id', $older->id)->where('facet', 'validity_end')->count());
        $this->assertSame(2, TagEvent::where('facet', 'resolution')->count());
    }

    public function test_supersede_is_idempotent_and_can_never_extend_a_window(): void
    {
        $older = $this->fact('Grupos 1 y 2', 'Seis meses', '2021-01-01', '2023-06-30');
        $newer = $this->fact('Grupo 2', 'Cuatro meses', '2026-01-01');
        $newer->update(['duplicate_of_id' => $older->id]);

        $this->postJson("/admin/reference-facts/{$newer->uuid}/resolve-duplicate",
            ['action' => 'supersede', 'newer_uuid' => $newer->uuid], $this->auth())->assertStatus(200);

        // The old window already ended BEFORE the boundary — closing must not push it
        // out to 2025-12-31, which would make the old fact answer for years it never
        // covered.
        $this->assertSame('2023-06-30', $older->fresh()->validity_end->toDateString());
    }

    public function test_the_direction_of_a_supersede_is_never_guessed(): void
    {
        $a = $this->fact('Grupos 1 y 2', 'Seis meses', '2026-01-01');
        $b = $this->fact('Grupo 2', 'Cuatro meses', '2026-01-01'); // SAME start date
        $b->update(['duplicate_of_id' => $a->id]);

        $this->postJson("/admin/reference-facts/{$b->uuid}/resolve-duplicate",
            ['action' => 'supersede', 'newer_uuid' => $b->uuid], $this->auth())
            ->assertStatus(422)
            ->assertJson(['code' => 'newer_does_not_start_after_older']);

        $this->assertNull($a->fresh()->validity_end, 'A refused supersede must change nothing.');
        $this->assertNull($b->fresh()->resolution);

        // And the pair view says so up front rather than offering an impossible action.
        $this->getJson("/admin/reference-facts/{$b->uuid}/duplicate-pair", $this->auth())
            ->assertStatus(200)
            ->assertJson(['supersede_candidate' => ['possible' => false]]);
    }

    public function test_supersede_requires_the_newer_fact_to_have_a_start_date(): void
    {
        $older = $this->fact('Grupos 1 y 2', 'Seis meses', '2021-01-01');
        $newer = $this->fact('Grupo 2', 'Cuatro meses', null); // no start date
        $newer->update(['duplicate_of_id' => $older->id]);

        $this->postJson("/admin/reference-facts/{$newer->uuid}/resolve-duplicate",
            ['action' => 'supersede', 'newer_uuid' => $newer->uuid], $this->auth())
            ->assertStatus(422)
            ->assertJson(['code' => 'newer_has_no_validity_start']);
    }

    // ---- 4. Resolution reaches the answer through DATA ONLY -------------------

    /**
     * The point of the whole exercise. Before the supersede, two verified facts with
     * overlapping validity and differing values make the UNCHANGED 7c rule report
     * `ambiguous_conflict` → escalate. After it, the same rule sees one candidate
     * for a present-day question and answers — and a question dated in the old
     * window still gets the OLD value.
     *
     * No line of ReferenceFactAnswerService changed in 7d. The escalation stops
     * because the data was corrected.
     */
    public function test_the_unchanged_7c_rule_stops_escalating_once_the_data_is_corrected(): void
    {
        // A real employee in the scope, in group 2 — so the answer runs through the
        // genuine group tier, not a synthetic shortcut.
        $category = \App\Models\ConvenioJobCategory::create([
            'convenio_id' => $this->convenio->id, 'name' => 'Educador/a social', 'group_code' => '2',
        ]);
        $employee = \App\Models\Employee::create([
            'email' => 'g2@example.com', 'full_name' => 'Empleada G2',
            'convenio_id' => $this->convenio->id, 'job_category_id' => $category->id,
            'territory_id' => $this->convenio->territory_id, 'employment_type' => 'full_time', 'status' => 'active',
        ]);

        // THE ESCALATING SHAPE. Two verified facts matching group 2 with the SAME
        // validity_start and differing values. This — not a version with a later
        // start date — is what makes the 7c rule escalate: `selectMostRecent` already
        // prefers a later `validity_start`, so an honestly-dated version pair is
        // handled by ordering alone. The pair that stalls a chat turn is the one
        // where the dates carry no ordering.
        $older = $this->fact('Grupos 1 y 2', 'Seis meses', '2026-01-01');
        $newer = $this->fact('Grupo 2', 'Cuatro meses', '2026-01-01');
        $newer->update(['duplicate_of_id' => $older->id]);

        $answers = app(ReferenceFactAnswerService::class);

        $before = $answers->answer($employee, $this->periodo->id, \Illuminate\Support\Carbon::parse('2026-06-01'));
        $this->assertSame('escalate', $before['outcome']);
        $this->assertSame('ambiguous_conflict', $before['reference_fact']['validity_selection']);

        // A supersede is REFUSED while the dates don't support a direction — 7d will
        // not guess which of two same-dated facts is the newer version.
        $this->postJson("/admin/reference-facts/{$newer->uuid}/resolve-duplicate",
            ['action' => 'supersede', 'newer_uuid' => $newer->uuid], $this->auth())
            ->assertStatus(422)->assertJson(['code' => 'newer_does_not_start_after_older']);
        $this->assertSame('escalate', $answers->answer($employee, $this->periodo->id,
            \Illuminate\Support\Carbon::parse('2026-06-01'))['outcome'], 'A refused supersede changes nothing.');

        // So the human does the two things the data actually needs, both through
        // EXISTING routes: correct the older fact's start date (the 7b-1 edit path,
        // scope-confirm gated), then supersede.
        $this->patchJson("/admin/reference-facts/{$older->uuid}", [
            'validity_start' => '2021-01-01', 'confirm_scope_change' => true,
        ], $this->auth())->assertStatus(200);

        $this->postJson("/admin/reference-facts/{$newer->uuid}/resolve-duplicate",
            ['action' => 'supersede', 'newer_uuid' => $newer->uuid], $this->auth())->assertStatus(200);

        // AFTER: the present-day question is answered, not escalated. NOTHING in the
        // answer loop changed — the older fact simply fell out of the validity
        // predicate it was always subject to. Resolution reached chat through data.
        $after = $answers->answer($employee, $this->periodo->id, \Illuminate\Support\Carbon::parse('2026-06-01'));
        $this->assertSame('answer', $after['outcome']);
        $this->assertStringContainsString('Cuatro meses', $after['answer']);
        $this->assertSame($newer->id, $after['reference_fact']['fact_id']);

        // AND HISTORY STAYS CORRECT: a 2025-dated question still gets the 2025 value.
        // This is the whole reason a supersede closes a window instead of deleting.
        $historical = $answers->answer($employee, $this->periodo->id, \Illuminate\Support\Carbon::parse('2025-03-01'));
        $this->assertSame('answer', $historical['outcome']);
        $this->assertStringContainsString('Seis meses', $historical['answer']);
        $this->assertSame($older->id, $historical['reference_fact']['fact_id']);
    }

    /**
     * The other half of the honest picture: an already well-dated version pair needs
     * no resolution to be ANSWERED correctly — the 7c validity ordering handles it.
     * What the supersede adds there is lineage and a closed window (the audit and
     * the queue), not a changed answer. Worth pinning so nobody later "fixes" the
     * answer loop for a problem it does not have.
     */
    public function test_a_well_dated_version_pair_already_answers_correctly_before_any_resolution(): void
    {
        $category = \App\Models\ConvenioJobCategory::create([
            'convenio_id' => $this->convenio->id, 'name' => 'Educador/a social', 'group_code' => '2',
        ]);
        $employee = \App\Models\Employee::create([
            'email' => 'g2b@example.com', 'full_name' => 'Empleada G2',
            'convenio_id' => $this->convenio->id, 'job_category_id' => $category->id,
            'territory_id' => $this->convenio->territory_id, 'employment_type' => 'full_time', 'status' => 'active',
        ]);

        $this->fact('Grupos 1 y 2', 'Seis meses', '2021-01-01');
        $this->fact('Grupo 2', 'Cuatro meses', '2026-01-01');

        $answers = app(ReferenceFactAnswerService::class);
        $now = $answers->answer($employee, $this->periodo->id, \Illuminate\Support\Carbon::parse('2026-06-01'));

        $this->assertSame('answer', $now['outcome']);
        $this->assertSame('most_recent_validity', $now['reference_fact']['validity_selection']);
        $this->assertStringContainsString('Cuatro meses', $now['answer']);
    }

    // ---- 5. coexist / reject ---------------------------------------------------

    public function test_coexist_records_a_judgement_and_touches_no_validity(): void
    {
        $a = $this->fact('Grupos 1 y 2', 'Seis meses', '2021-01-01');
        $b = $this->fact('Grupo 2', 'Cuatro meses', '2026-01-01');
        $b->update(['duplicate_of_id' => $a->id]);

        $this->postJson("/admin/reference-facts/{$b->uuid}/resolve-duplicate",
            ['action' => 'coexist', 'note' => 'distintas subáreas'], $this->auth())
            ->assertStatus(200)->assertJson(['action' => 'coexist', 'validity_changed' => false]);

        $this->assertSame('coexists', $a->fresh()->resolution);
        $this->assertSame('coexists', $b->fresh()->resolution);
        $this->assertNull($a->fresh()->validity_end);
        $this->assertNull($b->fresh()->validity_end);
        $this->assertSame($a->id, $b->fresh()->duplicate_of_id, 'The link is kept as history.');
        $this->assertSame(2, ReferenceFact::count());
    }

    public function test_reject_clears_the_flag_and_never_touches_a_verified_fact(): void
    {
        $a = $this->fact('Grupos 1 y 2', 'Seis meses', '2021-01-01');
        $verified = $this->fact('Grupo 2', 'Cuatro meses', '2026-01-01', null, 'verified');
        $verified->update(['duplicate_of_id' => $a->id]);

        $this->postJson("/admin/reference-facts/{$verified->uuid}/resolve-duplicate",
            ['action' => 'reject', 'note' => 'no son versiones'], $this->auth())
            ->assertStatus(200)->assertJson(['action' => 'reject', 'fact_status' => 'verified']);

        $this->assertSame('rejected_duplicate', $verified->fresh()->resolution);
        $this->assertSame('verified', $verified->fresh()->status, 'Rejecting a FLAG must not reject the FACT.');

        // An unverified proposal, on the other hand, follows the existing 7b-2
        // rejected-status semantics — auditable, never deleted.
        $proposal = $this->fact('Grupo 3', 'Un mes', '2026-01-01', null, 'needs_review');
        $proposal->update(['duplicate_of_id' => $a->id]);
        $this->postJson("/admin/reference-facts/{$proposal->uuid}/resolve-duplicate",
            ['action' => 'reject'], $this->auth())->assertStatus(200);
        $this->assertSame('rejected', $proposal->fresh()->status);
        $this->assertSame(3, ReferenceFact::count(), 'Nothing is ever deleted.');
    }

    // ---- 6. The pair view + the gate ------------------------------------------

    public function test_the_pair_view_names_exactly_what_differs(): void
    {
        $older = $this->fact('Grupos 1 y 2', 'Seis meses', '2021-01-01');
        $newer = $this->fact('Grupo 2', 'Cuatro meses', '2026-01-01');
        $newer->update(['duplicate_of_id' => $older->id]);

        $response = $this->getJson("/admin/reference-facts/{$newer->uuid}/duplicate-pair", $this->auth())
            ->assertStatus(200);

        $this->assertCount(2, $response->json('pair'));
        $differing = $response->json('differing_fields');
        $this->assertContains('value', $differing);
        $this->assertContains('group_label', $differing);
        $this->assertContains('validity_start', $differing);
        $this->assertNotContains('convenio', $differing, 'Identical fields must be reported as identical.');
        $this->assertNotContains('topic', $differing);
        $this->assertTrue($response->json('supersede_candidate.possible'));
        $this->assertSame('2025-12-31', $response->json('supersede_candidate.would_close_older_at'));

        // Reachable from EITHER side of the pair.
        $this->getJson("/admin/reference-facts/{$older->uuid}/duplicate-pair", $this->auth())->assertStatus(200);
    }

    public function test_resolution_is_gated_by_knowledge_edit(): void
    {
        $a = $this->fact('Grupos 1 y 2', 'Seis meses', '2021-01-01');
        $b = $this->fact('Grupo 2', 'Cuatro meses', '2026-01-01');
        $b->update(['duplicate_of_id' => $a->id]);

        $auditor = Admin::create(['email' => 'auditor7d@example.com', 'full_name' => 'Auditor', 'status' => 'active']);
        $auditor->assignRole('auditor');
        $this->app['auth']->forgetGuards();
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $headers = ['Authorization' => 'Bearer '.$auditor->createToken('t')->plainTextToken, 'Accept' => 'application/json'];

        // Reads stay open (an auditor browses); the WRITE is refused.
        $this->getJson("/admin/reference-facts/{$b->uuid}/duplicate-pair", $headers)->assertStatus(200);
        $this->postJson("/admin/reference-facts/{$b->uuid}/resolve-duplicate",
            ['action' => 'coexist'], $headers)->assertStatus(403);

        $this->assertNull($b->fresh()->resolution);
    }
}
