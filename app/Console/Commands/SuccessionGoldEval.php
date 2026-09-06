<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Services\SuccessionProposalService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Sprint 7d (ADR-0024), part C — the succession GOLD EVAL. Read-only.
 *
 * Measures the deterministic relationship rule against HUMAN-LABELED real corpus
 * pairs (`hr-docs/sprints/sprint-07d/eval/succession-gold.json`), and reports the
 * only number that matters for this feature:
 *
 *     CONFIDENTLY-WRONG SUCCESSOR — a pair labeled `coexisting_sibling` or
 *     `conflict` that the rule called `successor`.
 *
 * That is the output that would tempt a human to retire a live document, so it is
 * the failure mode with a population-sized cost. `uncertain` on a true successor
 * is a MISS, not a failure: the human still sees the expiry task and the candidate
 * list 7a already gave them, so a missed suggestion costs a little work, while a
 * confident wrong one costs trust. The report separates the two for that reason.
 *
 * It writes NOTHING: no review tasks, no proposals, no documents. Everything runs
 * inside a rolled-back transaction as a belt-and-braces guarantee, and the
 * classifier is called through `SuccessionProposalService::compute()`, the same
 * code path the queued job runs.
 */
class SuccessionGoldEval extends Command
{
    protected $signature = 'succession:gold-eval
                            {--file= : gold fixture path (default hr-docs/sprints/sprint-07d/eval/succession-gold.json)}
                            {--discover : ignore the fixture; enumerate every same-convenio pair in this corpus and report what the rule says}
                            {--limit=25 : cap on expiring documents in --discover mode}
                            {--json : machine-readable output}';

    protected $description = 'Measure the succession relationship rule against labeled real corpus pairs (read-only).';

    public function handle(SuccessionProposalService $proposer): int
    {
        if ($this->option('discover')) {
            return $this->discover($proposer);
        }

        return $this->labeled($proposer);
    }

    /**
     * The labeled eval: score the fixture's human-labeled pairs.
     */
    private function labeled(SuccessionProposalService $proposer): int
    {
        $path = $this->option('file')
            ?: base_path('../hr-docs/sprints/sprint-07d/eval/succession-gold.json');

        if (! is_file($path)) {
            $this->error("Gold fixture not found: {$path}");

            return self::FAILURE;
        }

        /** @var array{pairs: list<array<string,mixed>>} $gold */
        $gold = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $pairs = $gold['pairs'] ?? [];

        $rows = [];
        // Read-only by construction: nothing in here writes, and the rollback means a
        // future edit that starts writing still cannot touch the corpus.
        DB::beginTransaction();
        try {
            foreach ($pairs as $pair) {
                $rows[] = $this->evaluate($proposer, $pair);
            }
        } finally {
            DB::rollBack();
        }

        $tally = [
            'right' => 0,          // the rule agreed with the label
            'uncertain' => 0,      // the rule declined to claim (a miss, acceptable)
            'wrong_successor' => 0,// THE ONE THAT MATTERS
            'other_wrong' => 0,    // wrong, but not a confident successor claim
            'skipped' => 0,        // the pair is not in this database
        ];
        foreach ($rows as $r) {
            $tally[$r['verdict']]++;
        }

        if ($this->option('json')) {
            $this->line(json_encode([
                'thresholds' => [
                    'overlap' => (float) config('hr.succession_overlap_threshold'),
                    'sibling_ceiling' => (float) config('hr.succession_sibling_ceiling'),
                ],
                'tally' => $tally, 'pairs' => $rows,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return $tally['wrong_successor'] === 0 ? self::SUCCESS : self::FAILURE;
        }

        $this->info('Succession gold eval — labeled real corpus pairs (read-only)');
        $this->line(sprintf('  thresholds: overlap ≥ %.2f AND strictly-later validity → successor; ≤ %.2f → sibling',
            (float) config('hr.succession_overlap_threshold'), (float) config('hr.succession_sibling_ceiling')));
        $this->newLine();

        foreach ($rows as $r) {
            $mark = match ($r['verdict']) {
                'right' => '<info>RIGHT</info>',
                'uncertain' => '<comment>UNCERTAIN (miss)</comment>',
                'wrong_successor' => '<error>CONFIDENTLY-WRONG SUCCESSOR</error>',
                'other_wrong' => '<comment>WRONG (not a successor claim)</comment>',
                default => 'SKIPPED',
            };
            $this->line("  [{$r['id']}] {$mark}");
            $this->line("      expected: {$r['expected']}   got: ".($r['got'] ?? '—')
                .'   score: '.($r['score'] !== null ? sprintf('%.4f', $r['score']) : '—'));
            $this->line("      {$r['note']}");
        }

        $this->newLine();
        $this->line(sprintf('  right %d | uncertain(miss) %d | wrong-but-not-successor %d | SKIPPED %d',
            $tally['right'], $tally['uncertain'], $tally['other_wrong'], $tally['skipped']));

        if ($tally['wrong_successor'] > 0) {
            $this->error("  CONFIDENTLY-WRONG SUCCESSORS: {$tally['wrong_successor']} — must be 0. Lower nothing; RAISE hr.succession_overlap_threshold, or the pair's validity dates are wrong in the registry.");

            return self::FAILURE;
        }
        $this->info('  CONFIDENTLY-WRONG SUCCESSORS: 0');

        if ($tally['skipped'] === count($pairs) && $pairs !== []) {
            $this->warn('  Every pair was skipped — this database does not hold the labeled corpus. The eval has not measured anything; run it against the corpus database.');
        }

        return self::SUCCESS;
    }

    /**
     * Discovery mode — no labels needed, so it runs on any corpus on day one.
     *
     * The plan's §4.6 said the build turn should "confirm each pair by SQL". This is
     * that SQL, made repeatable: every document with a validity_end in a convenio
     * holding more than one document is run through the real classifier, and every
     * `successor` claim is printed with the passages' scores and both validity
     * windows so a human can label it in one pass. The output doubles as the fixture
     * skeleton for the labeled eval above.
     *
     * Also the cheapest way to answer the question that actually matters before
     * go-live: HOW MANY successor claims does this rule make on the real corpus, and
     * is each one defensible?
     */
    private function discover(SuccessionProposalService $proposer): int
    {
        // Convenios holding more than one document — the only place succession can
        // arise, since candidates are same-convenio by construction.
        $convenioIds = DB::table('documents')
            ->select('convenio_id')
            ->whereNotNull('convenio_id')
            ->groupBy('convenio_id')
            ->havingRaw('count(*) > 1')
            ->pluck('convenio_id');

        $expiring = Document::query()
            ->whereIn('convenio_id', $convenioIds)
            ->whereNotNull('validity_end')
            ->orderBy('validity_end')
            ->limit((int) $this->option('limit'))
            ->get();

        $rows = [];
        DB::beginTransaction();
        try {
            foreach ($expiring as $doc) {
                $computed = $proposer->compute($doc);
                $proposal = $computed['proposal'];
                $rows[] = [
                    'expiring_document_id' => $doc->id,
                    'expiring_title' => $doc->title,
                    'expiring_validity' => [$doc->validity_start?->toDateString(), $doc->validity_end?->toDateString()],
                    'relationship' => $proposal['relationship'] ?? null,
                    'reason' => $computed['reason'],
                    'candidate_document_id' => $proposal['candidate_document_id'] ?? null,
                    'candidate_title' => $proposal['candidate_title'] ?? null,
                    'candidate_validity' => $proposal['validity']['candidate'] ?? null,
                    'max_score' => $proposal['max_score'] ?? null,
                    'all_candidate_scores' => $computed['scored'],
                ];
            }
        } finally {
            DB::rollBack();
        }

        if ($this->option('json')) {
            $this->line(json_encode(['mode' => 'discover', 'pairs' => $rows], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $successors = 0;
        foreach ($rows as $r) {
            $this->line("  [{$r['expiring_document_id']}] ".mb_substr((string) $r['expiring_title'], 0, 70));
            $this->line('      '.implode(' → ', array_map(fn ($d) => $d ?? '?', $r['expiring_validity']))
                .'  ⇒  '.($r['relationship'] ?? 'nothing: '.$r['reason']));
            if ($r['candidate_document_id'] !== null) {
                $this->line(sprintf('      candidate [%d] %s (%s) score %.4f',
                    $r['candidate_document_id'], mb_substr((string) $r['candidate_title'], 0, 60),
                    implode(' → ', array_map(fn ($d) => $d ?? '?', (array) $r['candidate_validity'])), (float) $r['max_score']));
            }
            if ($r['relationship'] === SuccessionProposalService::SUCCESSOR) {
                $successors++;
            }
        }

        $this->newLine();
        $this->info(sprintf('  %d document(s) examined; %d successor claim(s) to label.', count($rows), $successors));
        $this->line('  Label each one in hr-docs/sprints/sprint-07d/eval/succession-gold.json, then run without --discover.');

        return self::SUCCESS;
    }

    /**
     * One labeled pair.
     *
     * @param  array<string,mixed>  $pair
     * @return array<string,mixed>
     */
    private function evaluate(SuccessionProposalService $proposer, array $pair): array
    {
        $expected = (string) $pair['expected'];
        $base = ['id' => (string) $pair['id'], 'expected' => $expected, 'got' => null, 'score' => null];

        $expiring = $this->resolve($pair['expiring'] ?? []);
        $candidate = $this->resolve($pair['candidate'] ?? []);

        if ($expiring === null || $candidate === null) {
            return $base + ['verdict' => 'skipped', 'note' => 'document(s) not found in this database (title fingerprint '.($expiring === null ? 'expiring' : 'candidate').' unmatched)'];
        }
        if ($expiring->convenio_id !== $candidate->convenio_id) {
            // Not a defect in the rule — a defect in the fixture. Succession is
            // same-convenio by construction, so such a pair can never be proposed.
            return $base + ['verdict' => 'skipped', 'note' => 'fixture error: the pair is not same-convenio, so it is never a succession candidate'];
        }

        $computed = $proposer->compute($expiring);
        if ($computed['proposal'] === null) {
            return $base + ['verdict' => 'skipped', 'note' => 'nothing computable: '.$computed['reason']];
        }

        $score = $computed['scored'][$candidate->id] ?? null;
        if ($score === null) {
            return $base + ['verdict' => 'skipped', 'note' => 'the labeled candidate returned no comparable chunk (not embedded?)'];
        }

        [$got] = $proposer->relationship($expiring, $candidate, (float) $score);
        $topPick = (int) $computed['proposal']['candidate_document_id'];

        $verdict = match (true) {
            $got === $expected => 'right',
            $got === SuccessionProposalService::UNCERTAIN => 'uncertain',
            $got === SuccessionProposalService::SUCCESSOR => 'wrong_successor',
            default => 'other_wrong',
        };

        return [
            'id' => (string) $pair['id'],
            'expected' => $expected,
            'got' => $got,
            'score' => round((float) $score, 6),
            'verdict' => $verdict,
            'expiring_document_id' => $expiring->id,
            'candidate_document_id' => $candidate->id,
            'top_pick_document_id' => $topPick,
            'top_pick_is_this_candidate' => $topPick === $candidate->id,
            'note' => sprintf(
                '%s (%s → %s) vs %s (%s → %s)%s',
                mb_substr($expiring->title, 0, 60), $expiring->validity_start?->toDateString() ?? '?', $expiring->validity_end?->toDateString() ?? '?',
                mb_substr($candidate->title, 0, 60), $candidate->validity_start?->toDateString() ?? '?', $candidate->validity_end?->toDateString() ?? '?',
                $topPick === $candidate->id ? '' : "  [note: the proposal's top pick was document {$topPick}]",
            ),
        ];
    }

    /**
     * Resolve a fixture reference to a document. The fixture carries BOTH an id (as
     * `deploy.md` records them) and a title fingerprint, and the id is only trusted
     * when the fingerprint matches — ids are environment-local, and silently
     * evaluating the wrong document would make the eval a lie.
     *
     * @param  array<string,mixed>  $ref
     */
    private function resolve(array $ref): ?Document
    {
        $fingerprint = (string) ($ref['title_contains'] ?? '');

        if (isset($ref['document_id'])) {
            $byId = Document::find((int) $ref['document_id']);
            if ($byId !== null && ($fingerprint === '' || mb_stripos($byId->title, $fingerprint) !== false)) {
                return $byId;
            }
        }

        if ($fingerprint === '') {
            return null;
        }

        $matches = Document::where('title', 'ilike', '%'.$fingerprint.'%')
            ->when(isset($ref['convenio_numero']), fn ($q) => $q->whereHas('convenio', fn ($c) => $c->where('numero', $ref['convenio_numero'])))
            ->get();

        // Ambiguity is a skip, not a guess.
        return $matches->count() === 1 ? $matches->first() : null;
    }
}
