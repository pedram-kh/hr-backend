<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesGateCases;
use App\Models\ChatSession;
use App\Models\Convenio;
use App\Models\Employee;
use App\Models\Territory;
use App\Services\Agent\ControlTools;
use App\Services\Agent\PlannerClient;
use App\Services\Agent\Rules\GeneralLanePostCheck;
use App\Services\Agent\Rules\NormalizationValidationRule;
use App\Services\Agent\ScopeSummaryBuilder;
use App\Services\Agent\ToolRegistry;
use App\Services\Agent\TurnState;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Sprint 13b (plan.md §7.4 S1, prompt iteration on the DEV bank only) — the cheapest way to see what the
 * planner proposes: ONE planner call per question (round 1 exactly as the agent sends it, `normalize_question`
 * included), then the real `NormalizationValidationRule` over whatever it proposed. NO tool runs, nothing is
 * answered, nothing is persisted (every case is rolled back). Roughly one /plan call (~$0.01) per question.
 *
 * Fixtures: any gate bank (cases with a scope/email, run as `answer:gate` would) or the negatives file
 * (`literal` + `over_reach_canonical`; run as `--email`). A frozen bank is refused unless its sha256 matches
 * MANIFEST.sha256, EXCEPT with `--allow-unfrozen` (prompt iteration on the dev bank only — never the held-out).
 *
 * "Escaped" = the validator ACCEPTED a proposal that an independent oracle says over-reached: the standing G4
 * oracle is `GeneralLanePostCheck::audit()` (digit / spelled number / entitlement word the literal lacks), with a
 * cruder second opinion (territory/convenio names, pay words not in the literal). It must be 0; it is measured
 * independently of `NormalizationDiff` on purpose.
 */
class NormalizationProbe extends Command
{
    use ResolvesGateCases;

    protected $signature = 'normalization:probe
        {--set= : gate bank json, or normalization-negatives.json}
        {--email= : run every case as this employee (required for the negatives file)}
        {--filter= : run only cases whose id matches this regex}
        {--only-live : negatives only — just the cases flagged s1_live}
        {--stream= : append each row as a JSON line to this file}
        {--budget-usd= : stop when the measured spend reaches this}
        {--allow-unfrozen : skip the MANIFEST.sha256 check (dev-bank prompt iteration only)}
        {--json : machine-readable output}';

    protected $description = 'Planner-only round 1 + the real normalization validator over a bank (no tools, no answers, rolled back).';

    /** claude-sonnet-5 list price $3/$15 per MTok — reporting only. */
    private const PRICE = [3.00, 15.00];

    /** Words that must not APPEAR in an accepted canonical unless the employee said them (independent oracle). */
    private const ORACLE_WORDS = ['grupo', 'nivel', 'categoría', 'categoria', 'euros', '€', '%', 'salario', 'sueldo'];

    public function handle(PlannerClient $planner, ScopeSummaryBuilder $scopeSummary, ToolRegistry $tools, NormalizationValidationRule $rule): int
    {
        $path = (string) $this->option('set');
        if ($path === '' || ! is_file($path)) {
            $this->error('--set=<path> is required and must exist.');

            return self::FAILURE;
        }
        $manifestError = $this->frozen($path);
        if ($manifestError !== null && ! $this->option('allow-unfrozen')) {
            $this->error($manifestError);

            return self::FAILURE;
        }

        $fixture = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $cases = [];
        foreach ($fixture['cases'] ?? [] as $i => $raw) {
            if (isset($raw['literal'])) { // the negatives file
                if ($this->option('only-live') && ! ($raw['s1_live'] ?? false)) {
                    continue;
                }
                $cases[] = ['id' => (string) ($raw['id'] ?? "n{$i}"), 'email' => $this->option('email') ?: null, 'scope' => null,
                    'question' => (string) $raw['literal'], 'expect' => [], 'class' => $raw['class'] ?? null, 'topic' => null, 'negative' => true];

                continue;
            }
            foreach ($this->normalizeCase($raw, $i) as $c) {
                $cases[] = $c + ['topic' => $raw['topic'] ?? null, 'negative' => false];
            }
        }
        if ($this->option('filter')) {
            $rx = '/'.str_replace('/', '\\/', (string) $this->option('filter')).'/u';
            $cases = array_values(array_filter($cases, fn ($c) => preg_match($rx, $c['id']) === 1));
        }

        $budget = $this->option('budget-usd') !== null && $this->option('budget-usd') !== '' ? (float) $this->option('budget-usd') : null;
        $definitions = [...$tools->definitions(), ...ControlTools::definitions(), ControlTools::normalizationDefinition()];

        $rows = [];
        $spent = 0.0;
        foreach ($cases as $case) {
            $case['email'] ??= $this->option('email') ?: null;
            ['employee' => $employee, 'note' => $note] = $this->resolveEmployee($case);
            if ($employee === null) {
                $rows[] = ['id' => $case['id'], 'skipped' => true, 'note' => $note];

                continue;
            }
            DB::beginTransaction();
            try {
                $row = $this->probeOne($case, $employee, $planner, $scopeSummary, $definitions, $rule);
            } catch (\Throwable $e) {
                $row = ['id' => $case['id'], 'skipped' => false, 'error' => $e::class.': '.mb_substr($e->getMessage(), 0, 200)];
            } finally {
                DB::rollBack();
            }
            $rows[] = $row;
            $spent += (float) ($row['cost_usd'] ?? 0.0);
            if ($this->option('stream')) {
                file_put_contents((string) $this->option('stream'), json_encode($row, JSON_UNESCAPED_UNICODE)."\n", FILE_APPEND | LOCK_EX);
            }
            if ($budget !== null && $spent >= $budget) {
                $this->error(sprintf('BUDGET REACHED: $%.3f ≥ $%.2f — stopping after %d rows.', $spent, $budget, count($rows)));
                break;
            }
        }

        $summary = $this->summarize($rows);
        if ($this->option('json')) {
            $this->line(json_encode(['set' => $path, 'summary' => $summary, 'rows' => $rows], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        } else {
            foreach ($rows as $r) {
                $this->line($this->formatRow($r));
            }
            $this->newLine();
            $this->info('summary: '.json_encode($summary, JSON_UNESCAPED_UNICODE));
        }

        return ($summary['escaped'] ?? 0) === 0 && ($summary['errors'] ?? 0) === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  array<string,mixed>  $case
     * @param  list<array<string,mixed>>  $definitions
     * @return array<string,mixed>
     */
    private function probeOne(array $case, Employee $employee, PlannerClient $planner, ScopeSummaryBuilder $scopeSummary, array $definitions, NormalizationValidationRule $rule): array
    {
        $session = ChatSession::create(['employee_id' => $employee->id, 'started_at' => now(), 'last_activity_at' => now()]);
        $state = new TurnState($employee, $case['question'], now(), $session, []);

        $plan = $planner->plan($case['question'], $scopeSummary->build($employee, $state->asOfDate), ['exchanges' => [], 'message_ids' => []], $definitions, []);
        $calls = $plan['calls'] ?? [];
        $firstTool = $calls[0]['tool'] ?? null;
        $norm = collect($calls)->firstWhere('tool', 'normalize_question');

        $tokens = $plan['tokens'] ?? [];
        $cost = ((int) ($tokens['prompt'] ?? 0) / 1e6) * self::PRICE[0] + ((int) ($tokens['completion'] ?? 0) / 1e6) * self::PRICE[1];

        $row = [
            'id' => $case['id'], 'skipped' => false, 'literal' => $case['question'], 'negative' => $case['negative'],
            'first_tool' => $firstTool, 'tools_proposed' => array_column($calls, 'tool'),
            'prompt_version' => $plan['prompt_version'] ?? null, 'cost_usd' => round($cost, 5), 'ms' => $plan['ms'] ?? null,
            'verdict' => 'absent', 'proposed' => null, 'rejected_by' => [], 'topic_ok' => null, 'escaped' => false, 'oracle' => [],
        ];
        if ($norm === null) {
            return $row;
        }

        $verdict = $rule->evaluate($state, $norm, null);
        $n = $state->normalization ?? [];
        $row['verdict'] = $n['verdict'] ?? 'absent';
        $row['proposed'] = $n['proposed'] ?? null;
        $row['rejected_by'] = array_values(array_unique(array_column($n['rejections'] ?? [], 'rule')));
        $row['used'] = $n['used'] ?? null;
        $row['topic_dropped'] = $n['topic_dropped'] ?? false;
        unset($verdict);

        if ($row['verdict'] === 'accepted') {
            $canonical = (string) ($n['used']['canonical_query'] ?? '');
            $row['oracle'] = $this->oracle($case['question'], $canonical);
            $row['escaped'] = $row['oracle'] !== [];
            if ($case['topic'] !== null && ($n['used']['topic_id'] ?? null) !== null) {
                $row['topic_ok'] = mb_strtolower((string) $n['used']['topic_name']) === mb_strtolower((string) $case['topic']);
            }
        }

        return $row;
    }

    /** @return list<string> reasons the canonical looks over-reaching, by a check independent of NormalizationDiff */
    private function oracle(string $literal, string $canonical): array
    {
        $flags = [];
        // The standing G4 oracle (plan.md §7.3): `GeneralLanePostCheck::audit()` — broader than the validator's own
        // scan and built from the same vocabulary. A hit counts when the canonical has it and the literal does not
        // (a figure or word the employee said is theirs to repeat).
        foreach (array_diff(GeneralLanePostCheck::audit($canonical), GeneralLanePostCheck::audit($literal)) as $hit) {
            $flags[] = "audit:{$hit}";
        }
        // Plus the cruder checks below (added pay words, territory/convenio names), kept as a second opinion.
        $lit = mb_strtolower($literal);
        $can = mb_strtolower($canonical);
        preg_match_all('/\d+/u', $can, $digits);
        foreach ($digits[0] as $d) {
            if (! str_contains($lit, $d)) {
                $flags[] = "digit:{$d}";
            }
        }
        foreach (self::ORACLE_WORDS as $w) {
            if (str_contains($can, $w) && ! str_contains($lit, $w)) {
                $flags[] = "word:{$w}";
            }
        }
        $names = array_merge(Territory::query()->pluck('name')->all(), Convenio::query()->pluck('name')->all());
        foreach ($names as $name) {
            $nm = mb_strtolower((string) $name);
            if (mb_strlen($nm) >= 4 && str_contains($can, $nm) && ! str_contains($lit, $nm)) {
                $flags[] = "name:{$nm}";
            }
        }

        return $flags;
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     * @return array<string,mixed>
     */
    private function summarize(array $rows): array
    {
        $scored = array_values(array_filter($rows, fn ($r) => ! ($r['skipped'] ?? false) && ! isset($r['error'])));
        $verdicts = array_count_values(array_map(fn ($r) => $r['verdict'].(($r['verdict'] === 'accepted' && ($r['used']['topic_id'] ?? null) === null) ? '_no_topic' : ''), $scored));
        $topicScored = array_values(array_filter($scored, fn ($r) => $r['topic_ok'] !== null));
        $rejectedBy = [];
        foreach ($scored as $r) {
            foreach ($r['rejected_by'] as $rule) {
                $rejectedBy[$rule] = ($rejectedBy[$rule] ?? 0) + 1;
            }
        }
        $declined = count(array_filter($scored, fn ($r) => $r['verdict'] === 'declined'));
        $lat = array_values(array_filter(array_column($scored, 'ms'), 'is_int'));
        sort($lat);

        return [
            'cases' => count($rows), 'scored' => count($scored), 'skipped' => count($rows) - count($scored) - count(array_filter($rows, fn ($r) => isset($r['error']))),
            'errors' => count(array_filter($rows, fn ($r) => isset($r['error']))),
            'verdicts' => $verdicts, 'declined' => $declined, 'rejected_by' => $rejectedBy,
            'topic_correct' => ['correct' => count(array_filter($topicScored, fn ($r) => $r['topic_ok'])), 'of' => count($topicScored)],
            'escaped' => count(array_filter($scored, fn ($r) => $r['escaped'])),
            'cost_usd' => round(array_sum(array_column($scored, 'cost_usd')), 4),
            'p50_ms' => $lat === [] ? null : $lat[(int) floor((count($lat) - 1) * 0.5)],
            'prompt_versions' => array_values(array_unique(array_filter(array_column($scored, 'prompt_version')))),
        ];
    }

    /** @param  array<string,mixed>  $r */
    private function formatRow(array $r): string
    {
        if ($r['skipped'] ?? false) {
            return "  [{$r['id']}] SKIPPED — {$r['note']}";
        }
        if (isset($r['error'])) {
            return "  [{$r['id']}] ERROR {$r['error']}";
        }
        $canon = $r['used']['canonical_query'] ?? ($r['proposed']['canonical_query'] ?? null);
        $topic = $r['used']['topic_name'] ?? ($r['proposed']['topic_id'] ?? null);
        $flag = $r['escaped'] ? ' ESCAPED('.implode(',', $r['oracle']).')' : '';
        $rej = $r['rejected_by'] !== [] ? ' by '.implode(',', $r['rejected_by']) : '';

        return sprintf("  [%s] %s%s%s\n      «%s»\n      → topic=%s canonical=«%s» conf=%s first_tool=%s", $r['id'], mb_strtoupper($r['verdict']), $rej, $flag, $r['literal'], $topic ?? '—', $canon ?? '—', $r['proposed']['confidence'] ?? '—', $r['first_tool'] ?? '—');
    }

    private function frozen(string $path): ?string
    {
        $manifest = dirname($path).'/MANIFEST.sha256';
        if (! is_file($manifest)) {
            return null;
        }
        foreach (preg_split('/\R/', (string) file_get_contents($manifest)) ?: [] as $line) {
            if (preg_match('/^([0-9a-f]{64})\s+\*?(.+)$/', trim($line), $m) === 1 && trim($m[2]) === basename($path)) {
                $actual = hash_file('sha256', $path);

                return $actual === $m[1] ? null : 'FROZEN FILE CHANGED: '.basename($path)." sha256 {$actual} != MANIFEST {$m[1]}.";
            }
        }

        return null;
    }
}
