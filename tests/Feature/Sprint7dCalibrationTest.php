<?php

namespace Tests\Feature;

use App\Models\Convenio;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Sector;
use App\Models\Territory;
use App\Services\ExtractionClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Sprint 7d (ADR-0024) — the calibration RULE is itself tested.
 *
 * `fence:calibrate-semantic` produces the numbers that become a safety gate's
 * thresholds, so the rule it applies must not be a matter of taste or a comment:
 *
 *   1. A block threshold is chosen only from LABELED evidence. With no
 *      known-true-overlap anchor, the command must refuse to recommend one —
 *      because there is then nothing to prove a threshold sits below a genuine
 *      overlap, and an unmeasured threshold on a safety gate is worse than the
 *      blunt fence it replaces.
 *   2. When labeled anchors exist, the recommended threshold sits STRICTLY BELOW
 *      the weakest known-true overlap (so no genuine overlap escapes) and the
 *      review band sits below the weakest same-topic-other-point score.
 *   3. The command writes NOTHING. It is safe to run against production.
 *
 * The distribution numbers themselves can only come from the deployed corpus;
 * what is verified here is that the arithmetic turning them into thresholds is
 * conservative in the right direction.
 */
class Sprint7dCalibrationTest extends TestCase
{
    use RefreshDatabase;

    private Convenio $convenio;

    private ScriptedCompareClient $ai;

    private string $fixture;

    protected function setUp(): void
    {
        parent::setUp();

        $territory = Territory::create(['code' => '31', 'name' => 'Navarra', 'level' => 'provincial', 'aliases' => []]);
        $sector = Sector::create(['name' => 'Acción e Intervención Social', 'aliases' => []]);
        $this->convenio = Convenio::create([
            'numero' => '31101815012021', 'name' => 'Navarra Intervención Social',
            'territory_id' => $territory->id, 'sector_id' => $sector->id,
        ]);
        $officialType = DocumentType::create(['code' => 'official_convenio', 'name' => 'Convenio oficial']);
        Document::create([
            'title' => 'Convenio Navarra Intervención Social',
            'storage_path' => 'documents/test/navarra.pdf',
            'convenio_id' => $this->convenio->id,
            'document_type_id' => $officialType->id,
            'authority_level' => 'official_convenio',
            'retrieval_status' => 'active',
            'language' => 'es',
        ]);

        $this->ai = new ScriptedCompareClient;
        $this->app->instance(ExtractionClient::class, $this->ai);

        $this->fixture = storage_path('framework/testing/anchors-7d.json');
        File::ensureDirectoryExists(dirname($this->fixture));
    }

    protected function tearDown(): void
    {
        File::delete($this->fixture);
        parent::tearDown();
    }

    /** @param  list<array{id:string,class:string,score:float}>  $anchors */
    private function writeAnchors(array $anchors): void
    {
        File::put($this->fixture, json_encode(['anchors' => array_map(fn ($a) => [
            'id' => $a['id'],
            'class' => $a['class'],
            'convenio_numero' => '31101815012021',
            'probe' => 'probe text for '.$a['id'],
        ], $anchors)]));

        foreach ($anchors as $a) {
            $this->ai->scoreByProbe['probe text for '.$a['id']] = $a['score'];
        }
    }

    /** @return array<string,mixed> */
    private function calibrate(): array
    {
        $exit = \Illuminate\Support\Facades\Artisan::call('fence:calibrate-semantic', [
            '--anchors' => true, '--anchors-file' => $this->fixture, '--json' => true,
        ]);
        $this->assertSame(0, $exit);

        return json_decode(\Illuminate\Support\Facades\Artisan::output(), true) ?? [];
    }

    public function test_it_refuses_to_recommend_a_block_threshold_without_labeled_evidence(): void
    {
        // Only unlabeled-equivalent classes: nothing establishes a true overlap.
        $this->writeAnchors([
            ['id' => 'b1', 'class' => 'same_topic_other_point', 'score' => 0.61],
            ['id' => 'c1', 'class' => 'unrelated', 'score' => 0.33],
        ]);

        $report = $this->calibrate();

        $this->assertFalse($report['recommendation']['ready']);
        $this->assertArrayNotHasKey('semantic_conflict_threshold', $report['recommendation'],
            'No threshold may be recommended without a known-true-overlap anchor.');
        $this->assertStringContainsString('CANNOT be chosen', $report['recommendation']['note']);
    }

    public function test_the_recommended_threshold_sits_below_the_weakest_true_overlap(): void
    {
        $this->writeAnchors([
            ['id' => 'a1', 'class' => 'paraphrase', 'score' => 0.88],
            ['id' => 'a2', 'class' => 'paraphrase', 'score' => 0.79], // the weakest true overlap
            ['id' => 'a3', 'class' => 'paraphrase', 'score' => 0.91],
            ['id' => 'b1', 'class' => 'same_topic_other_point', 'score' => 0.64],
            ['id' => 'b2', 'class' => 'same_topic_other_point', 'score' => 0.71],
            ['id' => 'c1', 'class' => 'unrelated', 'score' => 0.31],
        ]);

        $report = $this->calibrate();
        $rec = $report['recommendation'];

        $this->assertTrue($rec['ready']);
        $this->assertLessThan(0.79, $rec['semantic_conflict_threshold'],
            'The block threshold MUST sit below the weakest known-true overlap, or a genuine overlap escapes.');
        $this->assertLessThan(0.64, $rec['semantic_review_band'],
            'The review band must reach down to the class-(b) region it is meant to catch.');
        $this->assertLessThan($rec['semantic_conflict_threshold'], $rec['semantic_review_band'],
            'The band must be strictly below the block threshold, or the two bands invert.');

        // The chosen numbers carry the evidence that justifies them.
        $this->assertSame(0.79, $rec['justified_by']['weakest_true_overlap (class a min)']);
        $this->assertSame(0.64, $rec['justified_by']['weakest_same_topic_other_point (class b min)']);
    }

    public function test_a_weaker_true_overlap_pulls_the_threshold_down_never_up(): void
    {
        // The same set as above, plus one genuine overlap that scores poorly (e.g. a
        // convenio whose wording differs a lot). The threshold must FOLLOW it down.
        $this->writeAnchors([
            ['id' => 'a1', 'class' => 'paraphrase', 'score' => 0.88],
            ['id' => 'a2', 'class' => 'paraphrase', 'score' => 0.62],
            ['id' => 'b1', 'class' => 'same_topic_other_point', 'score' => 0.55],
        ]);

        $rec = $this->calibrate()['recommendation'];

        $this->assertLessThan(0.62, $rec['semantic_conflict_threshold'],
            'A weak-but-genuine overlap must drag the threshold DOWN (block more), never be discarded as an outlier.');
    }

    public function test_the_calibration_run_writes_nothing(): void
    {
        $this->writeAnchors([['id' => 'a1', 'class' => 'paraphrase', 'score' => 0.9]]);

        $before = [
            'documents' => Document::count(),
            'events' => \App\Models\EscalationEvent::count(),
            'tasks' => \App\Models\DocumentReviewTask::count(),
            'tag_events' => \App\Models\TagEvent::count(),
        ];

        $this->calibrate();

        $this->assertSame($before['documents'], Document::count());
        $this->assertSame($before['events'], \App\Models\EscalationEvent::count());
        $this->assertSame($before['tasks'], \App\Models\DocumentReviewTask::count());
        $this->assertSame($before['tag_events'], \App\Models\TagEvent::count());
    }
}

/** Returns a per-probe scripted score, so an anchor's "known truth" is exercised. */
class ScriptedCompareClient extends ExtractionClient
{
    /** @var array<string,float> */
    public array $scoreByProbe = [];

    public function compareScope(array $params): array
    {
        $probe = $params['texts'][0] ?? '';
        $score = $this->scoreByProbe[$probe] ?? 0.0;

        return [
            'matches' => [[
                'probe_index' => 0,
                'chunks' => [[
                    'id' => 1, 'document_id' => 1, 'chunk_index' => 0, 'page_from' => 1,
                    'content' => 'texto del convenio', 'authority_level' => 'official_convenio',
                    'score' => $score,
                ]],
            ]],
            'max_score' => $score,
            'eligible_total' => 12,
            'probe_count' => 1,
        ];
    }
}
