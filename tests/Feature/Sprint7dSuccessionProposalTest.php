<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Convenio;
use App\Models\Document;
use App\Models\DocumentReviewTask;
use App\Models\DocumentType;
use App\Models\Sector;
use App\Models\TagEvent;
use App\Models\Territory;
use App\Services\ExtractionClient;
use App\Services\SuccessionProposalService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Sprint 7d (ADR-0024), part C — the AI succession proposal is INERT, and the one
 * dangerous label is behind a conjunction.
 *
 * The harm this guards against is specific and was named in 7a: a confidently-
 * wrong successor is the output that would tempt a human to RETIRE a live
 * document, cutting off answers for a whole population. So:
 *
 *   1. `successor` requires overlap ≥ threshold AND a strictly-later
 *      `validity_start`. Either alone is not enough — proven by driving the
 *      comparison to each combination.
 *   2. The proposal writes THREE COLUMNS ON THE TASK and nothing else. Not
 *      `predecessor_document_id`, not `retrieval_status`, not `validity_*`, not any
 *      `documents` column.
 *   3. Confirming runs the UNCHANGED 7a write-side: lineage is written, the
 *      predecessor is NOT retired, and retiring still needs both explicit flags.
 *   4. Rejecting writes nothing but an audit row, and leaves the task OPEN.
 *   5. Candidates are same-convenio only, in the proposal as well as in the write.
 */
class Sprint7dSuccessionProposalTest extends TestCase
{
    use RefreshDatabase;

    private Convenio $convenio;

    private Document $expiring;

    private Document $candidate;

    private Admin $admin;

    private ScriptedSuccessionClient $ai;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);

        $territory = Territory::create(['code' => '48', 'name' => 'Vizcaya', 'level' => 'provincial', 'aliases' => []]);
        $sector = Sector::create(['name' => 'Intervención Social', 'aliases' => []]);
        $this->convenio = Convenio::create([
            'numero' => '48006185012006', 'name' => 'Vizcaya Intervención Social',
            'territory_id' => $territory->id, 'sector_id' => $sector->id,
        ]);
        $type = DocumentType::create(['code' => 'convenio_text', 'name' => 'Texto de convenio']);

        // The expiring document and a later same-convenio document — the real corpus
        // shape `deploy.md` §5 names (an expired convenio prose text plus a newer one).
        $this->expiring = Document::create([
            'title' => 'Convenio Vizcaya Intervención Social 2019-2024',
            'storage_path' => 'documents/test/vizcaya-2019.pdf',
            'convenio_id' => $this->convenio->id, 'document_type_id' => $type->id,
            'authority_level' => 'official_convenio', 'retrieval_status' => 'active',
            'validity_start' => '2019-01-01', 'validity_end' => '2024-12-31', 'language' => 'es',
        ]);
        $this->candidate = Document::create([
            'title' => 'Convenio Vizcaya Intervención Social 2025-2028',
            'storage_path' => 'documents/test/vizcaya-2025.pdf',
            'convenio_id' => $this->convenio->id, 'document_type_id' => $type->id,
            'authority_level' => 'official_convenio', 'retrieval_status' => 'active',
            'validity_start' => '2025-01-01', 'validity_end' => null, 'language' => 'es',
        ]);

        $this->admin = Admin::create(['email' => 'kc7dc@example.com', 'full_name' => 'KC 7d C', 'status' => 'active']);
        $this->admin->assignRole('super_admin');

        $this->ai = new ScriptedSuccessionClient;
        $this->ai->candidateDocumentId = $this->candidate->id;
        $this->app->instance(ExtractionClient::class, $this->ai);
    }

    private function task(?Document $doc = null): DocumentReviewTask
    {
        return DocumentReviewTask::create([
            'document_id' => ($doc ?? $this->expiring)->id,
            'type' => 'expiry', 'status' => 'open',
            'due_date' => ($doc ?? $this->expiring)->validity_end?->toDateString(),
        ]);
    }

    /** @return array<string,string> */
    private function auth(): array
    {
        $this->app['auth']->forgetGuards();
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        return ['Authorization' => 'Bearer '.$this->admin->createToken('t')->plainTextToken, 'Accept' => 'application/json'];
    }

    // ---- 1. The conjunction ---------------------------------------------------

    /**
     * The load-bearing test for (C). `successor` needs BOTH high overlap AND a
     * strictly-later validity_start. Each half alone must produce something else.
     */
    public function test_successor_is_emitted_only_when_overlap_and_later_validity_both_hold(): void
    {
        $threshold = (float) config('hr.succession_overlap_threshold');
        $proposer = app(SuccessionProposalService::class);

        // (a) high overlap + strictly later → successor.
        $this->ai->score = $threshold + 0.05;
        $task = $this->task();
        $proposer->propose($task);
        $this->assertSame('successor', $task->fresh()->ai_proposal['relationship']);

        // (b) high overlap, NOT strictly later (the candidate starts EARLIER) →
        // conflict, never successor. A similarity model's opinion alone cannot
        // produce the dangerous label.
        $this->candidate->forceFill(['validity_start' => '2015-01-01'])->save();
        $task = $this->task();
        $proposer->propose($task);
        $this->assertSame('conflict', $task->fresh()->ai_proposal['relationship']);

        // (c) same start date → still not a successor (no ordering).
        $this->candidate->forceFill(['validity_start' => '2019-01-01'])->save();
        $task = $this->task();
        $proposer->propose($task);
        $this->assertSame('conflict', $task->fresh()->ai_proposal['relationship']);

        // (d) missing validity on either side → still not a successor.
        $this->candidate->forceFill(['validity_start' => null])->save();
        $task = $this->task();
        $proposer->propose($task);
        $this->assertSame('conflict', $task->fresh()->ai_proposal['relationship']);

        // (e) strictly later but LOW overlap → a coexisting sibling, not a successor.
        $this->candidate->forceFill(['validity_start' => '2025-01-01'])->save();
        $this->ai->score = (float) config('hr.succession_sibling_ceiling') - 0.10;
        $task = $this->task();
        $proposer->propose($task);
        $this->assertSame('coexisting_sibling', $task->fresh()->ai_proposal['relationship']);

        // (f) in between → no relationship claimed, with the reason stated.
        $this->ai->score = ((float) config('hr.succession_sibling_ceiling') + $threshold) / 2;
        $task = $this->task();
        $proposer->propose($task);
        $proposal = $task->fresh()->ai_proposal;
        $this->assertSame('uncertain', $proposal['relationship']);
        $this->assertNotEmpty($proposal['uncertainty']['reason']);
    }

    // ---- 2. Inertness: three columns, nothing else -----------------------------

    public function test_the_proposal_writes_only_the_three_task_columns_and_never_touches_a_document(): void
    {
        $this->ai->score = 0.9;
        $task = $this->task();

        $documentsBefore = Document::orderBy('id')->get()->map(fn ($d) => $d->getAttributes())->toArray();
        $tagEventsBefore = TagEvent::count();

        app(SuccessionProposalService::class)->propose($task);

        // Every column of every document is byte-identical.
        $documentsAfter = Document::orderBy('id')->get()->map(fn ($d) => $d->getAttributes())->toArray();
        $this->assertEquals($documentsBefore, $documentsAfter,
            'The AI must not write ANY documents column — not lineage, not status, not validity.');

        // Specifically, and named because these are the dangerous ones:
        $this->assertNull($this->candidate->fresh()->predecessor_document_id);
        $this->assertSame('active', $this->expiring->fresh()->retrieval_status);
        $this->assertSame('2024-12-31', $this->expiring->fresh()->validity_end->toDateString());

        // The proposal is a claim with its evidence, and it is marked as a machine's.
        $fresh = $task->fresh();
        $this->assertSame(DocumentReviewTask::PROPOSAL_PROPOSED, $fresh->ai_proposal_status);
        $this->assertSame('ai_agent', $fresh->ai_proposal['source']);
        $this->assertNotEmpty($fresh->ai_proposal['passages'][0]['expiring_excerpt']);
        $this->assertNotEmpty($fresh->ai_proposal['passages'][0]['candidate_excerpt']);
        $this->assertSame('open', $fresh->status, 'A proposal must not resolve the task.');

        // And it writes no provenance of its own on the document — there is nothing to
        // provenance yet, because nothing happened to the document.
        $this->assertSame($tagEventsBefore, TagEvent::count());
    }

    public function test_the_proposal_never_leaves_the_convenio(): void
    {
        // A newer document in a DIFFERENT convenio, which must never be proposed.
        $otherTerritory = Territory::create(['code' => '20', 'name' => 'Gipuzkoa', 'level' => 'provincial', 'aliases' => []]);
        $otherConvenio = Convenio::create([
            'numero' => '20100025012011', 'name' => 'Gipuzkoa Intervención Social',
            'territory_id' => $otherTerritory->id, 'sector_id' => $this->convenio->sector_id,
        ]);
        $foreign = Document::create([
            'title' => 'Convenio Gipuzkoa Intervención Social 2026',
            'storage_path' => 'documents/test/gipuzkoa.pdf',
            'convenio_id' => $otherConvenio->id, 'document_type_id' => $this->expiring->document_type_id,
            'authority_level' => 'official_convenio', 'retrieval_status' => 'active',
            'validity_start' => '2026-01-01', 'language' => 'es',
        ]);

        $this->ai->score = 0.99;
        $task = $this->task();
        app(SuccessionProposalService::class)->propose($task);

        // The candidate id list hr-backend sent could not contain the foreign document.
        $this->assertNotContains($foreign->id, $this->ai->lastCandidateIds);
        $this->assertContains($this->candidate->id, $this->ai->lastCandidateIds);
        $this->assertSame($this->candidate->id, $task->fresh()->ai_proposal['candidate_document_id']);
    }

    public function test_no_candidate_or_failed_comparison_proposes_nothing_and_says_why(): void
    {
        // (a) nothing to compare against.
        $this->candidate->delete();
        $task = $this->task();
        app(SuccessionProposalService::class)->propose($task);
        $this->assertNull($task->fresh()->ai_proposal['relationship']);
        $this->assertNull($task->fresh()->ai_proposal_status);
        $this->assertStringContainsString('no other document', $task->fresh()->ai_proposal['reason']);

        // (b) the comparison fails → recorded, not guessed at, and never thrown into
        // the caller (the expiry queue is still useful without a suggestion).
        $again = Document::create([
            'title' => 'Otro', 'storage_path' => 'x.pdf', 'convenio_id' => $this->convenio->id,
            'document_type_id' => $this->expiring->document_type_id, 'authority_level' => 'official_convenio',
            'retrieval_status' => 'active', 'validity_start' => '2025-01-01', 'language' => 'es',
        ]);
        $this->ai->candidateDocumentId = $again->id;
        $this->ai->throw = true;
        $task2 = $this->task();
        app(SuccessionProposalService::class)->propose($task2);
        $this->assertNull($task2->fresh()->ai_proposal['relationship']);
        $this->assertStringContainsString('comparison unavailable', $task2->fresh()->ai_proposal['reason']);
    }

    // ---- 3. Confirm runs the UNCHANGED 7a write-side --------------------------

    public function test_confirm_writes_lineage_through_the_7a_path_and_never_retires_the_predecessor(): void
    {
        $this->ai->score = 0.9;
        $task = $this->task();
        app(SuccessionProposalService::class)->propose($task);
        $this->assertSame('successor', $task->fresh()->ai_proposal['relationship']);

        $this->postJson("/admin/review/expiry/{$task->id}/resolve", [
            'action' => 'link_successor',
            'successor_uuid' => $this->candidate->uuid,
        ], $this->auth())->assertStatus(200)->assertJson([
            'action' => 'link_successor',
            'retired' => false,
            'ai_proposal_status' => 'confirmed',
        ]);

        // The lineage is written by the 7a path, with admin_manual provenance — the
        // human's action, not the AI's.
        $this->assertSame($this->expiring->id, $this->candidate->fresh()->predecessor_document_id);
        $event = TagEvent::where('facet', 'predecessor')->sole();
        $this->assertSame('admin_manual', $event->source);
        $this->assertSame($this->admin->id, $event->actor_id);

        // THE PREDECESSOR IS NOT RETIRED. Confirming a successor is not retiring.
        $this->assertSame('active', $this->expiring->fresh()->retrieval_status);
        $this->assertSame('resolved', $task->fresh()->status);
    }

    public function test_retiring_still_needs_both_explicit_flags_even_with_an_ai_proposal(): void
    {
        $this->ai->score = 0.9;
        $task = $this->task();
        app(SuccessionProposalService::class)->propose($task);

        // retire without the scope confirmation → 409, nothing written.
        $this->postJson("/admin/review/expiry/{$task->id}/resolve", [
            'action' => 'link_successor', 'successor_uuid' => $this->candidate->uuid,
            'retire_predecessor' => true,
        ], $this->auth())->assertStatus(409)->assertJson(['scope_affecting' => true]);

        $this->assertSame('active', $this->expiring->fresh()->retrieval_status);
        $this->assertNull($this->candidate->fresh()->predecessor_document_id);
        $this->assertSame('open', $task->fresh()->status);
    }

    // ---- 4. Reject writes nothing ---------------------------------------------

    public function test_rejecting_a_proposal_writes_no_lineage_and_leaves_the_task_open(): void
    {
        $this->ai->score = 0.9;
        $task = $this->task();
        app(SuccessionProposalService::class)->propose($task);

        $this->postJson("/admin/review/expiry/{$task->id}/reject-proposal",
            ['note' => 'no es una versión, es un acuerdo parcial'], $this->auth())
            ->assertStatus(200)->assertJson(['ai_proposal_status' => 'rejected', 'task_status' => 'open']);

        $this->assertNull($this->candidate->fresh()->predecessor_document_id);
        $this->assertSame('active', $this->expiring->fresh()->retrieval_status);
        $this->assertSame('open', $task->fresh()->status, 'Rejecting a suggestion does not resolve the expiry.');

        // The rejection is auditable, and the proposal itself is KEPT for the eval trail.
        $this->assertNotNull($task->fresh()->ai_proposal);
        $this->assertSame('rejected', TagEvent::where('facet', 'succession_proposal')->sole()->new_value);
    }

    // ---- 5. Wiring + gating ---------------------------------------------------

    public function test_the_expiry_scan_queues_a_proposal_without_touching_any_document(): void
    {
        \Illuminate\Support\Facades\Queue::fake();

        // The expiring document's validity_end is in the past → it qualifies.
        $this->artisan('reviews:scan-expiry')->assertExitCode(0);

        $task = DocumentReviewTask::where('type', 'expiry')->where('document_id', $this->expiring->id)->sole();
        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\ProposeSuccession::class,
            fn ($job) => $job->taskId === $task->id);

        // The command's own contract is unchanged: no status, no lineage.
        $this->assertSame('active', $this->expiring->fresh()->retrieval_status);
        $this->assertNull($this->candidate->fresh()->predecessor_document_id);
    }

    // ---- 6. The gold eval harness itself --------------------------------------

    /**
     * The eval must be able to FAIL, and must write nothing. A harness that cannot
     * report a confidently-wrong successor would make the (C) number meaningless, so
     * the harness is tested with a deliberately mislabeled pair.
     */
    public function test_the_gold_eval_detects_a_confidently_wrong_successor_and_writes_nothing(): void
    {
        $fixture = tempnam(sys_get_temp_dir(), 'gold').'.json';

        // Label the (genuinely later, genuinely similar) pair as a SIBLING. The rule
        // says `successor` → that is exactly a confidently-wrong successor, and the
        // command must exit non-zero.
        file_put_contents($fixture, json_encode(['pairs' => [[
            'id' => 'mislabeled-on-purpose',
            'expected' => 'coexisting_sibling',
            'expiring' => ['document_id' => $this->expiring->id, 'title_contains' => '2019-2024'],
            'candidate' => ['document_id' => $this->candidate->id, 'title_contains' => '2025-2028'],
        ]]]));

        $this->ai->score = 0.9;
        $documentsBefore = Document::orderBy('id')->get()->map(fn ($d) => $d->getAttributes())->toArray();
        $tasksBefore = DocumentReviewTask::count();

        $this->artisan("succession:gold-eval --file={$fixture}")
            ->expectsOutputToContain('CONFIDENTLY-WRONG SUCCESSOR')
            ->assertExitCode(1);

        // Read-only: no documents changed, no review tasks or proposals created.
        $this->assertEquals($documentsBefore, Document::orderBy('id')->get()->map(fn ($d) => $d->getAttributes())->toArray());
        $this->assertSame($tasksBefore, DocumentReviewTask::count());

        // Correctly labeled, the same pair passes.
        file_put_contents($fixture, json_encode(['pairs' => [[
            'id' => 'correctly-labeled',
            'expected' => 'successor',
            'expiring' => ['document_id' => $this->expiring->id, 'title_contains' => '2019-2024'],
            'candidate' => ['document_id' => $this->candidate->id, 'title_contains' => '2025-2028'],
        ]]]));
        $this->artisan("succession:gold-eval --file={$fixture}")
            ->expectsOutputToContain('CONFIDENTLY-WRONG SUCCESSORS: 0')
            ->assertExitCode(0);

        unlink($fixture);
    }

    /**
     * A stale document id must never make the eval score the WRONG document — it
     * skips, loudly. (The shipped fixture's ids come from `deploy.md`, not from SQL.)
     */
    public function test_the_gold_eval_skips_a_pair_whose_title_fingerprint_does_not_match_the_id(): void
    {
        $fixture = tempnam(sys_get_temp_dir(), 'gold').'.json';
        file_put_contents($fixture, json_encode(['pairs' => [[
            'id' => 'stale-id',
            'expected' => 'successor',
            'expiring' => ['document_id' => $this->expiring->id, 'title_contains' => 'HOSTELERÍA HUESCA'],
            'candidate' => ['document_id' => $this->candidate->id, 'title_contains' => '2025-2028'],
        ]]]));

        $this->artisan("succession:gold-eval --file={$fixture}")
            ->expectsOutputToContain('SKIPPED')
            ->expectsOutputToContain('has not measured anything')
            ->assertExitCode(0);

        unlink($fixture);
    }

    public function test_the_propose_and_reject_endpoints_are_gated_by_knowledge_edit(): void
    {
        $this->ai->score = 0.9;
        $task = $this->task();
        app(SuccessionProposalService::class)->propose($task);

        $auditor = Admin::create(['email' => 'auditor7dc@example.com', 'full_name' => 'Auditor', 'status' => 'active']);
        $auditor->assignRole('auditor');
        $this->app['auth']->forgetGuards();
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $headers = ['Authorization' => 'Bearer '.$auditor->createToken('t')->plainTextToken, 'Accept' => 'application/json'];

        // The queue READ stays open (an auditor browses read-only) and shows the
        // proposal; the writes are refused.
        $this->getJson('/admin/review/expiry', $headers)->assertStatus(200)
            ->assertJsonPath('tasks.0.ai_proposal.relationship', 'successor')
            ->assertJsonPath('tasks.0.is_ai_proposed', true);
        $this->postJson("/admin/review/expiry/{$task->id}/propose-succession", [], $headers)->assertStatus(403);
        $this->postJson("/admin/review/expiry/{$task->id}/reject-proposal", [], $headers)->assertStatus(403);

        $this->assertSame(DocumentReviewTask::PROPOSAL_PROPOSED, $task->fresh()->ai_proposal_status);
    }
}

/** hr-ai stand-in for (C): one scripted score against one candidate document. */
class ScriptedSuccessionClient extends ExtractionClient
{
    public float $score = 0.9;

    public bool $throw = false;

    public ?int $candidateDocumentId = null;

    /** @var list<int> */
    public array $lastCandidateIds = [];

    public function compareScope(array $params): array
    {
        $this->lastCandidateIds = $params['candidate_document_ids'] ?? [];
        if ($this->throw) {
            throw new RuntimeException('hr-ai /compare-scope failed (502): connection refused');
        }

        return [
            'matches' => [[
                'probe_index' => 0,
                'probe_source' => ['document_id' => $params['document_ids'][0] ?? null, 'chunk_id' => 501, 'chunk_index' => 4],
                'probe_excerpt' => 'El periodo de prueba para los grupos 1, 2 y 3 será de tres meses.',
                'chunks' => [[
                    'id' => 902, 'document_id' => $this->candidateDocumentId, 'chunk_index' => 6,
                    'page_from' => 3, 'content' => 'El periodo de prueba del personal de los grupos 1 a 3 será de tres meses.',
                    'authority_level' => 'official_convenio', 'score' => $this->score,
                ]],
            ]],
            'max_score' => $this->score,
            'eligible_total' => 40,
            'probe_count' => 1,
        ];
    }
}
