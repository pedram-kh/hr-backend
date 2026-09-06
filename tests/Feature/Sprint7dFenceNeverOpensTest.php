<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Models\Convenio;
use App\Models\Document;
use App\Models\DocumentReviewTask;
use App\Models\DocumentTopic;
use App\Models\DocumentType;
use App\Models\Employee;
use App\Models\EscalationCard;
use App\Models\EscalationEvent;
use App\Models\Sector;
use App\Models\Territory;
use App\Models\Topic;
use App\Services\ExtractionClient;
use App\Services\GuardrailPolicy;
use App\Services\RulingPublisher;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Sprint 7d acceptance proof (ADR-0024) — THE FENCE NEVER OPENS.
 *
 * 7d added a semantic pass to the publish no-override fence. The single thing
 * that must be true of that change is that it is a DISJUNCTION:
 *
 *      fence = existing_block OR semantic_block
 *
 * i.e. a similarity model's opinion can add a block but can never remove one.
 * These seven cases prove it end-to-end through the real HTTP endpoint (the
 * Sprint-5 API-matrix posture), not through the unit under test in isolation:
 *
 *   1. The four Sprint-5 Correction-01 cases, re-run through the COMBINED fence
 *      with the semantic pass forced to "sees nothing" (0.0). 1–3 must still
 *      BLOCK, 4 must still ALLOW. This is the literal regression statement: the
 *      old block cannot depend on the new signal.
 *   2. Case 4 (the hole the old fence left) with the semantic pass at 0.95 →
 *      BLOCK, `reason = semantic_overlap`, with the matched chunks recorded.
 *   3. The 2×2 disjunction matrix asserted directly: blocked == (existing || semantic).
 *   4. The review band → 409 `publish_requires_acknowledgement`, draft untouched,
 *      then acknowledge → publishes and records the acknowledgement.
 *   5. The comparison THROWS → the acknowledge path, never a clean publish.
 *   6. Threshold monotonicity: lowering the threshold may only add blocks; no
 *      threshold value can un-block what the EXISTING fence blocks.
 *   7. The comparison is never even CALLED when the existing fence blocks (locks
 *      the short-circuit ordering: no network latency on the hot blocked path,
 *      and no way for a refactor to make the two terms interdependent).
 *
 * `Sprint5Correction01FenceTest` still asserts the same four cases against
 * `detectConflicts` directly; this class asserts them against the whole publish
 * path. Both must stay green — that is the point.
 */
class Sprint7dFenceNeverOpensTest extends TestCase
{
    use RefreshDatabase;

    private Convenio $convenio;

    private Document $convenioDoc;

    private Employee $employee;

    private Topic $vacaciones;

    private Topic $jornada;

    private Admin $admin;

    private FakeCompareClient $ai;

    private FakePublisher $publisher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        GuardrailPolicy::flush(); // the array cache survives the DB rollback

        $territory = Territory::create(['code' => '31', 'name' => 'Navarra', 'level' => 'provincial', 'aliases' => []]);
        $sector = Sector::create(['name' => 'Limpieza', 'aliases' => []]);
        $this->convenio = Convenio::create([
            'numero' => '31TEST7D01', 'name' => 'Limpieza Navarra (7d)',
            'territory_id' => $territory->id, 'sector_id' => $sector->id,
        ]);
        $this->employee = Employee::create([
            'email' => 'worker7d@example.com', 'full_name' => 'Worker 7d',
            'convenio_id' => $this->convenio->id, 'territory_id' => $territory->id,
            'employment_type' => 'full_time', 'status' => 'active',
        ]);

        $officialType = DocumentType::create(['code' => 'official_convenio', 'name' => 'Convenio oficial']);
        DocumentType::create(['code' => 'internal_hr_ruling', 'name' => 'Resolución interna']);

        $this->vacaciones = Topic::create(['name' => 'vacaciones', 'status' => 'approved']);
        $this->jornada = Topic::create(['name' => 'jornada', 'status' => 'approved']);

        // The active official convenio governing the asker's scope — deliberately
        // UNtagged by default (the sparse-topic-lens reality the fence survives).
        $this->convenioDoc = Document::create([
            'title' => 'Limpieza Navarra 2024-2027',
            'storage_path' => 'documents/test/limpieza-navarra.pdf',
            'convenio_id' => $this->convenio->id,
            'document_type_id' => $officialType->id,
            'authority_level' => 'official_convenio',
            'retrieval_status' => 'active',
            'language' => 'es',
        ]);

        $this->admin = Admin::create(['email' => 'agent7d@example.com', 'full_name' => 'Agent 7d', 'status' => 'active']);
        $this->admin->assignRole('super_admin');

        // The semantic comparison and the publisher are the only two collaborators
        // that leave the process. Both are faked so the fence's DECISION is what is
        // under test, with no network and no S3.
        $this->ai = new FakeCompareClient;
        $this->publisher = new FakePublisher;
        $this->app->instance(ExtractionClient::class, $this->ai);
        $this->app->instance(RulingPublisher::class, $this->publisher);
    }

    // ---- harness ------------------------------------------------------------

    private function tagConvenio(Topic $topic): void
    {
        DocumentTopic::create([
            'document_id' => $this->convenioDoc->id,
            'topic_id' => $topic->id,
            'source' => 'admin_manual',
        ]);
    }

    /** A fresh convertible card (`low_confidence` is in the baseline allow-set). */
    private function makeCard(): EscalationCard
    {
        $session = ChatSession::create(['employee_id' => $this->employee->id, 'started_at' => now(), 'last_activity_at' => now()]);
        $msg = ChatMessage::create(['session_id' => $session->id, 'role' => 'user', 'content' => '¿cuántos días de vacaciones tengo?']);

        return EscalationCard::create([
            'chat_session_id' => $session->id, 'source_message_id' => $msg->id,
            'employee_id' => $this->employee->id, 'reason' => 'low_confidence', 'status' => 'new',
        ]);
    }

    /** POST the resolve+convert (publish) attempt for a card. */
    private function attemptPublish(EscalationCard $card, ?int $topicId, bool $acknowledge = false): \Illuminate\Testing\TestResponse
    {
        $this->app['auth']->forgetGuards();
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $body = [
            'resolution_text' => 'Las vacaciones anuales del personal de limpieza son de 30 días naturales, '
                .'distribuidos según el calendario laboral acordado con el comité de empresa.',
            'convert' => true,
            'confirm_scope_change' => true,
        ];
        if ($topicId !== null) {
            $body['topic_id'] = $topicId;
        }
        if ($acknowledge) {
            $body['acknowledge_semantic_overlap'] = true;
        }

        return $this->postJson('/admin/escalations/'.$card->uuid.'/resolve', $body, [
            'Authorization' => 'Bearer '.$this->admin->createToken('t')->plainTextToken,
            'Accept' => 'application/json',
        ]);
    }

    /** One attempt in a given world; returns true if publish was BLOCKED. */
    private function isBlocked(?int $topicId): bool
    {
        $response = $this->attemptPublish($this->makeCard(), $topicId);

        return $response->status() === 409 && $response->json('code') === 'publish_blocked';
    }

    // ---- 1. REGRESSION: the four Sprint-5 cases through the combined fence ----

    /**
     * The load-bearing test. With the semantic pass forced to see NOTHING (0.0),
     * every Sprint-5 verdict must be reproduced exactly. If the semantic result
     * were participating in the existing block in any way, one of these flips.
     */
    public function test_case1_no_topic_still_blocks_with_semantic_seeing_nothing(): void
    {
        $this->ai->maxScore = 0.0;
        $this->assertTrue($this->isBlocked(null), 'Sprint-5 case 1 (no topic) must still BLOCK.');
    }

    public function test_case2_untagged_governing_convenio_still_blocks_with_semantic_seeing_nothing(): void
    {
        $this->ai->maxScore = 0.0;
        $this->assertSame(0, DocumentTopic::where('document_id', $this->convenioDoc->id)->count());
        $this->assertTrue($this->isBlocked($this->vacaciones->id), 'Sprint-5 case 2 (Correction-01) must still BLOCK.');
    }

    public function test_case3_convenio_tagged_same_topic_still_blocks_with_semantic_seeing_nothing(): void
    {
        $this->ai->maxScore = 0.0;
        $this->tagConvenio($this->vacaciones);
        $this->assertTrue($this->isBlocked($this->vacaciones->id), 'Sprint-5 case 3 (same topic) must still BLOCK.');
    }

    public function test_case4_convenio_tagged_other_topic_still_allows_with_semantic_seeing_nothing(): void
    {
        $this->ai->maxScore = 0.0;
        $this->tagConvenio($this->jornada);

        $response = $this->attemptPublish($this->makeCard(), $this->vacaciones->id);

        $response->assertStatus(200);
        $this->assertSame(1, $this->publisher->calls, 'Case 4 must still publish — the addition is precise, not blunt.');
        $this->assertSame('active', Document::where('authority_level', 'internal_hr_ruling')->sole()->retrieval_status);
    }

    // ---- 2. The semantic pass ADDS a block where the old fence had a hole -----

    /**
     * Case 4 is exactly the gap: a convenio tagged on a DIFFERENT topic passes the
     * topic lens, even when its text already governs the ruling's point. With a
     * real overlap the combined fence must now block — and record the evidence.
     */
    public function test_semantic_pass_blocks_the_case4_hole_and_records_the_matched_chunks(): void
    {
        $this->tagConvenio($this->jornada);
        $this->ai->maxScore = 0.95;
        $this->ai->matchDocumentId = $this->convenioDoc->id;

        $response = $this->attemptPublish($this->makeCard(), $this->vacaciones->id);

        $response->assertStatus(409)->assertJson([
            'code' => 'publish_blocked',
            'reason' => 'semantic_overlap',
        ]);
        $this->assertSame(0, $this->publisher->calls, 'A semantic block must not publish.');

        // The draft stays a draft, and an open conflict task routes it to a human.
        $draft = Document::where('authority_level', 'internal_hr_ruling')->sole();
        $this->assertSame('draft', $draft->retrieval_status);
        $this->assertDatabaseHas('document_review_tasks', [
            'document_id' => $draft->id, 'type' => 'conflict', 'status' => 'open',
        ]);

        // The evidence is machine-readable, not prose: chunk ids + scores + the
        // thresholds in force, so "why did this block?" survives re-calibration.
        $event = EscalationEvent::where('type', 'publish_blocked')->latest('id')->sole();
        $this->assertSame('semantic_overlap', $event->detail['reason']);
        $this->assertSame(0.95, $event->detail['max_score']);
        $this->assertNotEmpty($event->detail['matches']);
        $this->assertSame($this->convenioDoc->id, $event->detail['matches'][0]['document_id']);
        $this->assertNotEmpty($response->json('passages'), 'The human must be shown the overlapping passage.');

        // And the overlapping convenio document is named as the conflict.
        $this->assertSame($this->convenioDoc->uuid, $response->json('conflicts.0.uuid'));
    }

    // ---- 3. The disjunction, asserted directly --------------------------------

    /**
     * The algebraic claim itself: for every combination of the two terms, the
     * outcome is their OR. Tested rather than inferred from the code's shape.
     */
    public function test_the_fence_is_exactly_the_disjunction_of_its_two_terms(): void
    {
        foreach ([true, false] as $existingBlocks) {
            foreach ([0.95, 0.0] as $semanticScore) {
                // Rebuild the world per combination (the convenio's tags decide the
                // existing term: untagged → blocks; tagged on another topic → allows).
                DocumentTopic::where('document_id', $this->convenioDoc->id)->delete();
                if (! $existingBlocks) {
                    $this->tagConvenio($this->jornada);
                }
                Document::where('authority_level', 'internal_hr_ruling')->delete();
                $this->ai->maxScore = $semanticScore;
                $this->ai->matchDocumentId = $this->convenioDoc->id;
                $this->publisher->calls = 0;

                $semanticBlocks = $semanticScore >= (float) config('hr.semantic_conflict_threshold');
                $expected = $existingBlocks || $semanticBlocks;

                $this->assertSame(
                    $expected,
                    $this->isBlocked($this->vacaciones->id),
                    sprintf(
                        'blocked must equal (existing=%s || semantic=%s)',
                        $existingBlocks ? 'block' : 'allow',
                        $semanticBlocks ? 'block' : 'allow',
                    ),
                );
            }
        }
    }

    // ---- 4. The review band: asked, not refused -------------------------------

    /**
     * A plausible-but-not-certain overlap must neither block nor silently pass. It
     * asks — and until the human answers, NOTHING has happened: the draft is
     * untouched, no chunk is written, the card is not re-opened.
     */
    public function test_review_band_requires_acknowledgement_and_writes_nothing_until_given(): void
    {
        $this->tagConvenio($this->jornada);
        // Strictly inside the band, DERIVED from the configured thresholds rather
        // than hardcoded: the band is [review_band, conflict_threshold), and these
        // are calibration outputs (`fence:calibrate-semantic`) that move whenever
        // the corpus does. A literal 0.65 was inside the provisional band
        // (0.60-0.75) and fell outside the calibrated one (0.66-0.78), so the test
        // failed for a reason that had nothing to do with the behaviour it asserts.
        $bandScore = (float) config('hr.semantic_review_band')
            + ((float) config('hr.semantic_conflict_threshold') - (float) config('hr.semantic_review_band')) / 2;
        $this->ai->maxScore = $bandScore;
        $this->ai->matchDocumentId = $this->convenioDoc->id;
        $card = $this->makeCard();

        $first = $this->attemptPublish($card, $this->vacaciones->id);

        $first->assertStatus(409)->assertJson([
            'code' => 'publish_requires_acknowledgement',
            'reason' => 'semantic_near_overlap',
            'comparison_unavailable' => false,
        ]);
        $this->assertNotEmpty($first->json('passages'), 'The near-passages must be shown, or the ask is unanswerable.');
        $this->assertSame(0, $this->publisher->calls);

        $draft = Document::where('authority_level', 'internal_hr_ruling')->sole();
        $this->assertSame('draft', $draft->retrieval_status, 'The draft must be untouched by an unanswered ask.');
        $this->assertSame(0, DocumentReviewTask::where('document_id', $draft->id)->where('type', 'conflict')->count(),
            'An ask is not a rejection — no conflict task.');
        $this->assertSame('new', $card->fresh()->status, 'An ask must not re-open the card.');
        $this->assertDatabaseCount('document_chunks', 0);

        // The human reads the passages and says there is no overlap.
        $second = $this->attemptPublish($card, $this->vacaciones->id, acknowledge: true);

        $second->assertStatus(200);
        $this->assertSame(1, $this->publisher->calls);
        $this->assertSame('active', $draft->fresh()->retrieval_status);

        $ack = EscalationEvent::where('type', 'publish_acknowledged_overlap')->sole();
        $this->assertSame($bandScore, $ack->detail['max_score']);
        $this->assertSame('semantic_near_overlap', $ack->detail['reason']);
    }

    /** The acknowledgement is per-attempt, and it can NEVER satisfy a real block. */
    public function test_acknowledgement_cannot_unblock_a_semantic_block_or_a_structural_one(): void
    {
        $this->tagConvenio($this->jornada);
        $this->ai->maxScore = 0.95;
        $this->ai->matchDocumentId = $this->convenioDoc->id;

        $this->attemptPublish($this->makeCard(), $this->vacaciones->id, acknowledge: true)
            ->assertStatus(409)->assertJson(['code' => 'publish_blocked', 'reason' => 'semantic_overlap']);

        // And with the structural fence blocking (untagged convenio), the flag is
        // equally powerless.
        DocumentTopic::where('document_id', $this->convenioDoc->id)->delete();
        $this->attemptPublish($this->makeCard(), $this->vacaciones->id, acknowledge: true)
            ->assertStatus(409)->assertJson(['code' => 'publish_blocked']);

        $this->assertSame(0, $this->publisher->calls);
    }

    // ---- 5. Failure is never a pass ------------------------------------------

    /**
     * If the comparison cannot be made, the fence does not know whether there is
     * an overlap — and "I don't know" must never render as "no conflict". It falls
     * to the acknowledge path with the cause NAMED, so the prompt is
     * distinguishable from a real near-passage.
     */
    public function test_comparison_failure_falls_to_acknowledgement_never_a_clean_publish(): void
    {
        $this->tagConvenio($this->jornada);
        $this->ai->throw = true;

        $response = $this->attemptPublish($this->makeCard(), $this->vacaciones->id);

        $response->assertStatus(409)->assertJson([
            'code' => 'publish_requires_acknowledgement',
            'reason' => 'semantic_compare_unavailable',
            'comparison_unavailable' => true,
        ]);
        $this->assertSame(0, $this->publisher->calls, 'hr-ai being down must never yield a clean publish.');
        $this->assertSame('draft', Document::where('authority_level', 'internal_hr_ruling')->sole()->retrieval_status);
    }

    /**
     * The same rule for the OTHER way the comparison can come back empty-handed: a
     * governing convenio that contributed zero chunks (an unOCRd scan — the 7e
     * gap). "I could not read the convenio" is not evidence of no conflict.
     */
    public function test_unreadable_convenio_requires_acknowledgement_rather_than_passing(): void
    {
        $this->tagConvenio($this->jornada);
        $this->ai->eligibleTotal = 0; // the convenio is active, but has no text layer

        $this->attemptPublish($this->makeCard(), $this->vacaciones->id)
            ->assertStatus(409)
            ->assertJson([
                'code' => 'publish_requires_acknowledgement',
                'reason' => 'semantic_no_text_to_compare',
                'comparison_unavailable' => true,
            ]);
        $this->assertSame(0, $this->publisher->calls);
    }

    /**
     * The honest converse: a scope with NO active official convenio has nothing to
     * override, so it publishes — and does so WITHOUT calling the comparison at
     * all (decided from the registry, so hr-ai being down cannot make a
     * convenio-less scope unpublishable).
     */
    public function test_scope_with_no_official_convenio_publishes_without_calling_the_comparison(): void
    {
        $this->convenioDoc->forceFill(['retrieval_status' => 'historical'])->save();
        $this->ai->throw = true; // would fail loudly if it were called

        $this->attemptPublish($this->makeCard(), $this->vacaciones->id)->assertStatus(200);

        $this->assertSame(0, $this->ai->calls, 'No candidate documents → no network call.');
        $this->assertSame(1, $this->publisher->calls);
    }

    // ---- 6. Threshold monotonicity -------------------------------------------

    /**
     * Lowering a block-triggering threshold may only turn ALLOW into BLOCK (this is
     * why its admin-facing direction would have to be `min`, not ADR-0019's `max`
     * — see ADR-0024). And no threshold value, however permissive, may un-block
     * what the EXISTING fence blocks: that guards against a future "optimisation"
     * that collapses the short-circuit into a single condition.
     */
    public function test_threshold_monotonicity_and_no_threshold_can_open_the_existing_fence(): void
    {
        $this->tagConvenio($this->jornada); // existing term ALLOWS
        $this->ai->maxScore = 0.80;
        $this->ai->matchDocumentId = $this->convenioDoc->id;

        config(['hr.semantic_conflict_threshold' => 0.90]); // 0.80 < 0.90 → no block
        $this->assertFalse($this->isBlocked($this->vacaciones->id));
        Document::where('authority_level', 'internal_hr_ruling')->delete();

        config(['hr.semantic_conflict_threshold' => 0.70]); // LOWER → blocks MORE
        $this->assertTrue($this->isBlocked($this->vacaciones->id));

        // Now the existing term BLOCKS. No threshold — not even 1.0, which no score
        // can reach — may open it.
        DocumentTopic::where('document_id', $this->convenioDoc->id)->delete();
        Document::where('authority_level', 'internal_hr_ruling')->delete();
        foreach ([0.70, 0.90, 1.0] as $threshold) {
            config(['hr.semantic_conflict_threshold' => $threshold]);
            $this->assertTrue(
                $this->isBlocked($this->vacaciones->id),
                "The existing fence must block at threshold {$threshold} regardless of the semantic term.",
            );
            Document::where('authority_level', 'internal_hr_ruling')->delete();
        }
    }

    // ---- 7. The ordering: no comparison on the already-blocked path -----------

    /**
     * When the cheap structural SQL already blocks, the comparison must not even be
     * called. Two things this locks: no network latency on the common (blocked)
     * path, and — more importantly — no way for a later refactor to make the
     * existing verdict depend on the semantic one.
     */
    public function test_comparison_is_never_called_when_the_existing_fence_blocks(): void
    {
        $this->ai->throw = true; // if it is called at all, the outcome changes visibly

        // Case 1 (no topic) and case 2 (untagged governing convenio).
        $this->attemptPublish($this->makeCard(), null)->assertJson(['code' => 'publish_blocked']);
        $this->attemptPublish($this->makeCard(), $this->vacaciones->id)->assertJson(['code' => 'publish_blocked']);

        // Case 3 (convenio tagged with the ruling's topic).
        $this->tagConvenio($this->vacaciones);
        $this->attemptPublish($this->makeCard(), $this->vacaciones->id)->assertJson(['code' => 'publish_blocked']);

        $this->assertSame(0, $this->ai->calls, 'The semantic comparison must not run when the existing fence blocks.');
    }

    /** The probe splitter must cover the whole ruling — a dropped tail is a fail-open. */
    public function test_probe_split_covers_the_entire_text_however_long(): void
    {
        // A ruling far longer than the embedder's window, in many paragraphs.
        $paragraph = str_repeat('El personal tiene derecho a treinta días naturales de vacaciones. ', 12);
        $text = implode("\n\n", array_fill(0, 40, $paragraph));

        $probes = \App\Services\SemanticFenceService::probes($text);

        $this->assertLessThanOrEqual((int) config('hr.semantic_probe_max'), count($probes));
        $this->assertNotEmpty($probes);

        // Every word of the ruling is present in some probe (coverage, not sampling).
        $rejoined = preg_replace('/\s+/u', ' ', implode(' ', $probes));
        $normalized = trim((string) preg_replace('/\s+/u', ' ', $text));
        $this->assertSame(mb_strlen($normalized), mb_strlen((string) $rejoined),
            'The probes must cover the whole text — a truncated tail is a silent fail-open.');
    }
}

/**
 * hr-ai stand-in. Only `compareScope` matters here; every other endpoint is
 * unreachable in this test (the publisher is faked too), so a call to one would
 * be a bug the parent class surfaces.
 */
class FakeCompareClient extends ExtractionClient
{
    public float $maxScore = 0.0;

    public bool $throw = false;

    public int $eligibleTotal = 7;

    public ?int $matchDocumentId = null;

    public int $calls = 0;

    public function compareScope(array $params): array
    {
        $this->calls++;
        if ($this->throw) {
            throw new RuntimeException('hr-ai /compare-scope failed (502): connection refused');
        }

        return [
            'matches' => [[
                'probe_index' => 0,
                'probe_excerpt' => 'probe',
                'chunks' => $this->matchDocumentId === null ? [] : [[
                    'id' => 991, 'document_id' => $this->matchDocumentId, 'chunk_index' => 3,
                    'page_from' => 2, 'content' => 'Las vacaciones anuales serán de 30 días naturales.',
                    'authority_level' => 'official_convenio', 'score' => $this->maxScore,
                ]],
            ]],
            'max_score' => $this->maxScore,
            'eligible_total' => $this->eligibleTotal,
            'probe_count' => 1,
        ];
    }
}

/** Publisher stand-in: records that a publish happened; no PDF, no S3, no hr-ai. */
class FakePublisher extends RulingPublisher
{
    public int $calls = 0;

    public function __construct()
    {
        // No collaborators needed — publish() is fully replaced below.
    }

    public function publish(Document $document, string $resolutionText): array
    {
        $this->calls++;

        return ['chunks_written' => 3, 'page_count' => 1, 'round_trip' => ['lossless' => true]];
    }
}
