<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Models\EscalationResolution;
use App\Services\ExtractionClient;
use App\Services\SemanticFenceService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Sprint 7d (ADR-0024) — MEASURE the semantic fence's thresholds before shipping
 * them.
 *
 * WHY THIS COMMAND EXISTS. Nothing in the corpus tells us what cosine similarity
 * a genuine "the convenio already governs this point" overlap produces. The one
 * comparable number in the system, `hr.retrieval_score_floor = 0.40`, answers a
 * different question ("is this passage worth showing at all?") and is a poor
 * prior for "is this the same point?". Shipping an unmeasured threshold on a
 * SAFETY GATE is worse than the blunt fence it replaces: the blunt fence is at
 * least predictable. So the thresholds are measured, and the measurement is
 * recorded in review.md next to the values it justifies.
 *
 * TWO PASSES, BOTH REQUIRED — they answer different questions:
 *
 *  1. REAL DISTRIBUTION (`--real`). Every already-published `internal_hr_ruling`,
 *     compared against its own convenio's active `official_convenio` text. This
 *     shows the SHAPE of scores real rulings produce. What it cannot give is
 *     ground truth: nobody labelled those rulings as overlapping or not, and the
 *     corpus may hold very few of them.
 *  2. SYNTHETIC LABELED ANCHORS (`--anchors`). Probes whose true relationship is
 *     KNOWN, from `hr-docs/sprints/sprint-07d/eval/anchors.json`:
 *       (a) `paraphrase` — a near-verbatim restatement of a real convenio chunk.
 *           A true same-point overlap → anchors the BLOCK threshold.
 *       (b) `same_topic_other_point` — same subject area, different rule.
 *           → anchors the ACKNOWLEDGE band.
 *       (c) `unrelated` — an unrelated passage → the floor.
 *     Because the relationship is known, these are the only anchors that can say
 *     whether a threshold would have MISSED a real overlap. This pass is not a
 *     fallback for a small corpus; it is the labeled half of the evidence.
 *
 * THEN: set `semantic_conflict_threshold` BELOW the lowest class-(a) score (so no
 * known-true overlap escapes) and `semantic_review_band` to catch the class-(b)
 * region. Err toward blocking more — a false block routes to a human, a false
 * pass publishes a ruling on top of the convenio.
 *
 * READ-ONLY. This command writes NO row and mutates no config: it prints. Run it
 * against production data safely.
 */
class FenceCalibrateSemantic extends Command
{
    protected $signature = 'fence:calibrate-semantic
                            {--real : run the real published-ruling distribution pass}
                            {--anchors : run the labeled synthetic anchor pass}
                            {--anchors-file= : path to the anchors fixture (defaults to hr-docs/sprints/sprint-07d/eval/anchors.json)}
                            {--json : emit machine-readable JSON instead of tables}';

    protected $description = 'Measure semantic-similarity distributions to CHOOSE the fence thresholds (read-only; writes nothing).';

    /** Threshold pairs to report block/acknowledge counts at. */
    private const CANDIDATE_PAIRS = [
        [0.70, 0.55], [0.75, 0.60], [0.80, 0.65], [0.85, 0.70], [0.90, 0.75],
    ];

    public function handle(ExtractionClient $ai, SemanticFenceService $fence): int
    {
        // Default: both passes. Q1's resolution is that neither alone is enough.
        $doReal = $this->option('real') || ! $this->option('anchors');
        $doAnchors = $this->option('anchors') || ! $this->option('real');

        $report = ['generated_at' => now()->toIso8601String()];

        if ($doReal) {
            $report['real'] = $this->realDistribution($ai);
        }
        if ($doAnchors) {
            $report['anchors'] = $this->anchorPass($ai);
        }
        $report['recommendation'] = $this->recommend($report);

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->render($report);

        return self::SUCCESS;
    }

    /**
     * Pass 1 — the shape real rulings produce against their own scope's convenio.
     *
     * Uses the SAME probe splitter and the SAME `/compare-scope` parameters the
     * live fence uses (`SemanticFenceService::probes`), because a calibration run
     * on different inputs than production measures nothing.
     *
     * @return array<string,mixed>
     */
    private function realDistribution(ExtractionClient $ai): array
    {
        $rulings = Document::query()
            ->where('authority_level', 'internal_hr_ruling')
            ->whereNotNull('convenio_id')
            ->orderBy('id')
            ->get(['id', 'uuid', 'title', 'convenio_id', 'retrieval_status']);

        $rows = [];
        foreach ($rulings as $ruling) {
            $text = EscalationResolution::where('converted_to_document_id', $ruling->id)
                ->latest('id')->value('resolution_text');

            if ($text === null || trim($text) === '') {
                $rows[] = ['document_id' => $ruling->id, 'title' => $ruling->title, 'skipped' => 'no resolution_text'];

                continue;
            }

            $candidateIds = Document::query()
                ->where('convenio_id', $ruling->convenio_id)
                ->where('authority_level', 'official_convenio')
                ->where('retrieval_status', 'active')
                ->pluck('id')->map(fn ($id) => (int) $id)->all();

            if ($candidateIds === []) {
                $rows[] = ['document_id' => $ruling->id, 'title' => $ruling->title, 'skipped' => 'no active official_convenio in scope'];

                continue;
            }

            $probes = SemanticFenceService::probes($text);
            try {
                $result = $ai->compareScope([
                    'texts' => $probes,
                    'convenio_id' => $ruling->convenio_id,
                    'authority_levels' => ['official_convenio'],
                    'candidate_document_ids' => $candidateIds,
                    'retrieval_status' => [],
                    'as_of_date' => null,
                    'exclude_document_ids' => [$ruling->id],
                    'k' => 10,
                ]);
            } catch (Throwable $e) {
                $rows[] = ['document_id' => $ruling->id, 'title' => $ruling->title, 'skipped' => 'compare failed: '.$e->getMessage()];

                continue;
            }

            $scores = $this->allScores($result);
            $rows[] = [
                'document_id' => $ruling->id,
                'title' => $ruling->title,
                'probes' => count($probes),
                'eligible_total' => (int) ($result['eligible_total'] ?? 0),
                'max' => $this->quantile($scores, 1.0),
                'p90' => $this->quantile($scores, 0.90),
                'median' => $this->quantile($scores, 0.50),
                'min' => $this->quantile($scores, 0.0),
            ];
        }

        $measured = array_values(array_filter($rows, fn ($r) => ! isset($r['skipped'])));
        $maxima = array_map(fn ($r) => $r['max'], $measured);

        return [
            'rulings_found' => $rulings->count(),
            'rulings_measured' => count($measured),
            'rows' => $rows,
            'per_ruling_max' => [
                'min' => $this->quantile($maxima, 0.0),
                'median' => $this->quantile($maxima, 0.5),
                'max' => $this->quantile($maxima, 1.0),
            ],
            'at_candidate_pairs' => array_map(fn (array $pair) => [
                'threshold' => $pair[0],
                'review_band' => $pair[1],
                'would_block' => count(array_filter($maxima, fn ($m) => $m !== null && $m >= $pair[0])),
                'would_ask' => count(array_filter($maxima, fn ($m) => $m !== null && $m >= $pair[1] && $m < $pair[0])),
                'would_pass' => count(array_filter($maxima, fn ($m) => $m !== null && $m < $pair[1])),
            ], self::CANDIDATE_PAIRS),
        ];
    }

    /**
     * Pass 2 — the labeled anchors. Each fixture probe has a KNOWN class, so this
     * is the only pass that can tell us whether a candidate threshold would have
     * let a genuine same-point overlap through.
     *
     * @return array<string,mixed>
     */
    private function anchorPass(ExtractionClient $ai): array
    {
        $path = $this->option('anchors-file')
            ?: base_path('../hr-docs/sprints/sprint-07d/eval/anchors.json');

        if (! is_file($path)) {
            $this->warn("anchors fixture not found at {$path} — the labeled pass is MANDATORY (see ADR-0024).");

            return ['error' => "fixture not found: {$path}"];
        }

        $fixture = json_decode((string) file_get_contents($path), true);
        $anchors = $fixture['anchors'] ?? [];
        $rows = [];

        foreach ($anchors as $anchor) {
            $convenioNumero = (string) ($anchor['convenio_numero'] ?? '');
            $convenioId = \App\Models\Convenio::where('numero', $convenioNumero)->value('id');
            if ($convenioId === null) {
                $rows[] = ['id' => $anchor['id'] ?? '?', 'class' => $anchor['class'] ?? '?', 'skipped' => "convenio {$convenioNumero} not in this database"];

                continue;
            }

            $candidateIds = Document::query()
                ->where('convenio_id', $convenioId)
                ->where('authority_level', 'official_convenio')
                ->where('retrieval_status', 'active')
                ->pluck('id')->map(fn ($id) => (int) $id)->all();

            if ($candidateIds === []) {
                $rows[] = ['id' => $anchor['id'] ?? '?', 'class' => $anchor['class'] ?? '?', 'skipped' => 'no active official_convenio in that scope'];

                continue;
            }

            try {
                $result = $ai->compareScope([
                    'texts' => [(string) $anchor['probe']],
                    'convenio_id' => $convenioId,
                    'authority_levels' => ['official_convenio'],
                    'candidate_document_ids' => $candidateIds,
                    'retrieval_status' => [],
                    'as_of_date' => null,
                    'exclude_document_ids' => [],
                    'k' => 5,
                ]);
            } catch (Throwable $e) {
                $rows[] = ['id' => $anchor['id'] ?? '?', 'class' => $anchor['class'] ?? '?', 'skipped' => 'compare failed: '.$e->getMessage()];

                continue;
            }

            $rows[] = [
                'id' => $anchor['id'] ?? '?',
                'class' => $anchor['class'] ?? '?',
                'convenio' => $convenioNumero,
                'max_score' => isset($result['max_score']) ? (float) $result['max_score'] : null,
                'eligible_total' => (int) ($result['eligible_total'] ?? 0),
                // The matched excerpt is reported so the operator can VERIFY the
                // anchor's label against the convenio's actual words. An anchor
                // labelled `paraphrase` whose top match is an unrelated clause is a
                // broken anchor, and a threshold derived from it would be worthless.
                'top_excerpt' => mb_substr((string) ($result['matches'][0]['chunks'][0]['content'] ?? ''), 0, 160),
            ];
        }

        $byClass = [];
        foreach (['paraphrase', 'same_topic_other_point', 'unrelated'] as $class) {
            $scores = array_values(array_filter(array_map(
                fn ($r) => ($r['class'] ?? null) === $class ? ($r['max_score'] ?? null) : null,
                $rows,
            ), fn ($s) => $s !== null));
            $byClass[$class] = [
                'n' => count($scores),
                'min' => $this->quantile($scores, 0.0),
                'median' => $this->quantile($scores, 0.5),
                'max' => $this->quantile($scores, 1.0),
            ];
        }

        return ['fixture' => $path, 'rows' => $rows, 'by_class' => $byClass];
    }

    /**
     * The conservative rule, applied mechanically so the chosen numbers are not a
     * matter of taste: the block threshold sits strictly BELOW the weakest
     * known-true overlap (nothing genuine escapes), and the review band sits below
     * the weakest same-topic-other-point score (the plausible region is asked
     * about, not passed).
     *
     * @param  array<string,mixed>  $report
     * @return array<string,mixed>
     */
    private function recommend(array $report): array
    {
        $paraMin = $report['anchors']['by_class']['paraphrase']['min'] ?? null;
        $otherMin = $report['anchors']['by_class']['same_topic_other_point']['min'] ?? null;
        $unrelatedMax = $report['anchors']['by_class']['unrelated']['max'] ?? null;

        if ($paraMin === null) {
            return [
                'ready' => false,
                'note' => 'No labeled `paraphrase` anchor scored. A block threshold CANNOT be chosen from '
                    .'unlabeled data alone: without a known-true overlap there is nothing to prove the '
                    .'threshold sits below one. Keep the conservative config defaults and record this in review.md.',
            ];
        }

        // Round DOWN (block more), one step below the weakest true overlap.
        $threshold = floor(($paraMin - 0.02) * 100) / 100;
        $band = $otherMin !== null
            ? floor(($otherMin - 0.02) * 100) / 100
            : floor(($threshold - 0.15) * 100) / 100;

        return [
            'ready' => true,
            'semantic_conflict_threshold' => max(0.30, $threshold),
            'semantic_review_band' => max(0.20, min($band, $threshold - 0.02)),
            'justified_by' => [
                'weakest_true_overlap (class a min)' => $paraMin,
                'weakest_same_topic_other_point (class b min)' => $otherMin,
                'strongest_unrelated (class c max)' => $unrelatedMax,
            ],
            'note' => 'Block threshold is set BELOW the weakest known-true overlap so no genuine overlap escapes; '
                .'the band reaches down to the class-(b) region. If the class-(c) maximum exceeds the recommended '
                .'band, the corpus is noisier than the anchors assume — widen the anchor set before trusting it.',
        ];
    }

    /** @return list<float> */
    private function allScores(array $result): array
    {
        $scores = [];
        foreach ($result['matches'] ?? [] as $m) {
            foreach ($m['chunks'] ?? [] as $c) {
                $scores[] = (float) $c['score'];
            }
        }

        return $scores;
    }

    /** @param  list<float>  $values */
    private function quantile(array $values, float $q): ?float
    {
        if ($values === []) {
            return null;
        }
        sort($values);
        $idx = (int) round($q * (count($values) - 1));

        return round($values[$idx], 4);
    }

    /** @param  array<string,mixed>  $report */
    private function render(array $report): void
    {
        $this->newLine();
        $this->info('Sprint 7d — semantic fence calibration (READ-ONLY; nothing was written)');
        $this->line('generated_at: '.$report['generated_at']);

        if (isset($report['real'])) {
            $this->newLine();
            $this->info('── Pass 1: real published-ruling distribution ──────────────────────');
            $this->line("rulings found: {$report['real']['rulings_found']}  ·  measured: {$report['real']['rulings_measured']}");
            $this->table(
                ['doc', 'title', 'probes', 'eligible', 'max', 'p90', 'median', 'min', 'skipped'],
                array_map(fn ($r) => [
                    $r['document_id'] ?? '—', mb_substr((string) ($r['title'] ?? ''), 0, 34),
                    $r['probes'] ?? '—', $r['eligible_total'] ?? '—',
                    $r['max'] ?? '—', $r['p90'] ?? '—', $r['median'] ?? '—', $r['min'] ?? '—',
                    $r['skipped'] ?? '',
                ], $report['real']['rows']),
            );
            $this->line('per-ruling max: '.json_encode($report['real']['per_ruling_max']));
            $this->table(
                ['threshold', 'review_band', 'would BLOCK', 'would ASK', 'would pass'],
                array_map(fn ($r) => [$r['threshold'], $r['review_band'], $r['would_block'], $r['would_ask'], $r['would_pass']],
                    $report['real']['at_candidate_pairs']),
            );
            if ($report['real']['rulings_measured'] < 5) {
                $this->warn('Small N. This pass shows the SHAPE only — the labeled anchors below carry the threshold choice.');
            }
        }

        if (isset($report['anchors'])) {
            $this->newLine();
            $this->info('── Pass 2: labeled synthetic anchors (the ground truth) ────────────');
            if (isset($report['anchors']['error'])) {
                $this->error($report['anchors']['error']);
            } else {
                $this->line('fixture: '.$report['anchors']['fixture']);
                $this->table(
                    ['anchor', 'class (known truth)', 'convenio', 'max_score', 'eligible', 'top match (VERIFY the label)', 'skipped'],
                    array_map(fn ($r) => [
                        $r['id'] ?? '?', $r['class'] ?? '?', $r['convenio'] ?? '—',
                        $r['max_score'] ?? '—', $r['eligible_total'] ?? '—',
                        mb_substr((string) ($r['top_excerpt'] ?? ''), 0, 60), $r['skipped'] ?? '',
                    ], $report['anchors']['rows']),
                );
                $this->table(
                    ['class', 'n', 'min', 'median', 'max'],
                    array_map(fn ($k, $v) => [$k, $v['n'], $v['min'] ?? '—', $v['median'] ?? '—', $v['max'] ?? '—'],
                        array_keys($report['anchors']['by_class']), $report['anchors']['by_class']),
                );
            }
        }

        $this->newLine();
        $this->info('── Recommendation ──────────────────────────────────────────────────');
        foreach ($report['recommendation'] as $key => $value) {
            $this->line("  {$key}: ".(is_scalar($value) ? var_export($value, true) : json_encode($value)));
        }
        $this->newLine();
        $this->line('Set the chosen values in config/hr.php (HR_SEMANTIC_CONFLICT_THRESHOLD /');
        $this->line('HR_SEMANTIC_REVIEW_BAND) and paste this whole output into');
        $this->line('hr-docs/sprints/sprint-07d/review.md next to the values it justifies.');
    }
}
