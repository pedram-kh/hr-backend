<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesGateCases;
use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Models\Convenio;
use App\Models\Employee;
use App\Services\Agent\Rules\AskEmployeeWhitelist;
use App\Services\AnswerEngineDispatcher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Sprint 13, build step 7 (plan.md §E.14, §E.15 step 7) — run one gate
 * fixture through classic, agent, or both, and report pass rate / hard
 * false-answer count / path+authority distribution / first-tool+terminal
 * accuracy (agent) / rule overrides / cost+latency (informational).
 *
 * `App\Services\AnswerEngineDispatcher::handle()` is called DIRECTLY with an
 * explicit `$engine` (plan §E.14's own reason this argument exists), so
 * `--engine=both` never touches the global `HR_ANSWER_ENGINE`/DB override.
 *
 * **Fresh session per case, always** (plan §E.14's own bullet — the 10b §9
 * session-reuse incident, now also a correctness problem for the agent's
 * conversation window, §B.4.4): a brand-new `ChatSession` is created and its
 * uuid passed in, never the employee's last-24h session `resolveSession()`
 * would otherwise reuse. Repeats get their own fresh session too.
 *
 * **Default no writes:** every case runs inside a transaction rolled back at
 * the end, UNLESS `--persist` is passed AND the resolved employee's email is
 *
 * `test-*@example.com` — mirrors `EstatutoGoldEval`'s own hard gate exactly,
 * for exactly the same reason (never leave gate noise in a real employee's
 * conversation).
 *
 * Fixture shape accepts EITHER of two case conventions already in this repo,
 * normalized here rather than forcing every existing/future fixture into one
 * schema:
 *   - `email` + `question` (+ `expect.*`) — the convention `gold-2c.json`,
 *     `situational.json`, and this command's own test fixture use.
 *   - `convenio_id`/`group_label`/`job_category`/`as_of_date` +
 *     `canonical_question`/`colloquial_question` + `expected_path` +
 *     `value_contains` — `fact-routing.json`'s own pre-existing shape
 *     (§E.14 item 3), expanded into one gate case per question variant. A
 *     scope-based case that names a convenio/group/category this database
 *     does not have is reported "scope not found", never fabricated.
 *
 * `as_of_date` on a scope case is NOT honoured — no engine can pin a date
 * other than `Carbon::today()` yet (§F.15's own finding; real support is
 * ticketed, `hr-docs/roadmap.md` §7, post-pilot). Every scope case's report
 * row says so explicitly rather than silently running on today's date as if
 * the fixture's date had been applied.
 */
class AnswerGate extends Command
{
    use ResolvesGateCases;

    protected $signature = 'answer:gate
        {--engine=classic : classic|agent|both}
        {--set= : path to a gate fixture json}
        {--repeat=1 : repeats per case per engine}
        {--persist : keep persisted turns (test-*@example.com resolved employees only)}
        {--filter= : run only cases whose id matches this regex (chunk a long set)}
        {--class= : run only cases of this class}
        {--stream= : append every scored row as a JSON line to this file as it finishes (a long run survives a crash)}
        {--budget-usd= : Sprint 13b — hard spend cap for THIS run (list price). Refuses to start when the projection exceeds it, and stops mid-run when the measured spend reaches it}
        {--est-turn-usd=0.04 : projected cost per turn for the --budget-usd start-up check}
        {--allow-unfrozen : skip the MANIFEST.sha256 check on a frozen bank (never for a gate stage)}
        {--json : machine-readable output}';

    protected $description = 'Run a gate fixture through classic/agent/both and report pass rate, path/authority distribution, and (agent) routing accuracy.';

    private const ENGINES = ['classic', 'agent'];

    private bool $aborted = false;

    /** claude-sonnet-5 $3/$15 per MTok (sprint-10-M review §1, re-confirmed sprint-13 review step 6) — reporting only, never production logic. */
    private const PRICE_PER_MTOK = [
        'claude-sonnet-5' => [3.00, 15.00],
        'claude-sonnet-4-5' => [3.00, 15.00],
        'claude-haiku-4-5' => [1.00, 5.00],
    ];

    private const DEFAULT_PRICE = [3.00, 15.00];

    public function handle(AnswerEngineDispatcher $dispatcher): int
    {
        $engineOpt = (string) $this->option('engine');
        $engines = $engineOpt === 'both' ? self::ENGINES : [$engineOpt];
        foreach ($engines as $e) {
            if (! in_array($e, self::ENGINES, true)) {
                $this->error("Unknown --engine={$engineOpt}. One of: classic|agent|both.");

                return self::FAILURE;
            }
        }

        $path = $this->option('set');
        if (! $path || ! is_file($path)) {
            $this->error('--set=<path to gate fixture json> is required and must exist.');

            return self::FAILURE;
        }

        $repeat = max(1, (int) $this->option('repeat'));
        $persist = (bool) $this->option('persist');

        // Sprint 13b (plan.md §7.1): a frozen bank whose sha256 differs from MANIFEST.sha256 is refused.
        $frozen = $this->frozenBankCheck((string) $path);
        if ($frozen !== null && ! $this->option('allow-unfrozen')) {
            $this->error($frozen);

            return self::FAILURE;
        }

        /** @var array{cases?:list<array<string,mixed>>} $fixture */
        $fixture = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $rawCases = $fixture['cases'] ?? [];

        $cases = [];
        foreach ($rawCases as $i => $raw) {
            foreach ($this->normalizeCase($raw, $i) as $case) {
                $cases[] = $case;
            }
        }

        $filter = $this->option('filter');
        $classOpt = $this->option('class');
        if ($filter || $classOpt) {
            $cases = array_values(array_filter($cases, fn ($c) => (! $filter || preg_match('/'.str_replace('/', '\\/', (string) $filter).'/u', $c['id']) === 1)
                && (! $classOpt || ($c['class'] ?? null) === $classOpt)));
        }
        $stream = $this->option('stream');

        // Projected-spend guard: refuse to START a run whose projection exceeds the cap.
        $budget = $this->option('budget-usd') !== null && $this->option('budget-usd') !== '' ? (float) $this->option('budget-usd') : null;
        $turns = count($cases) * count($engines) * $repeat;
        $projected = $turns * (float) $this->option('est-turn-usd');
        if ($budget !== null) {
            $this->line(sprintf('  projected spend: %d turns × $%.3f = $%.2f (cap $%.2f)', $turns, (float) $this->option('est-turn-usd'), $projected, $budget));
            if ($projected > $budget) {
                $this->error('Projected spend exceeds --budget-usd; refusing to start. Narrow the run (--filter/--repeat) or raise the estimate honestly.');

                return self::FAILURE;
            }
        }

        $rows = [];
        $spent = 0.0;
        $aborted = false;
        foreach ($cases as $case) {
            foreach ($engines as $engine) {
                for ($r = 0; $r < $repeat; $r++) {
                    $rows[] = $row = $this->runCase($dispatcher, $case, $engine, $persist, $r);
                    $spent += (float) ($row['cost_usd'] ?? 0.0);
                    if ($stream) {
                        file_put_contents((string) $stream, json_encode($row, JSON_UNESCAPED_UNICODE)."\n", FILE_APPEND | LOCK_EX);
                    }
                    if ($budget !== null && $spent >= $budget) {
                        $aborted = true;
                        $this->error(sprintf('BUDGET REACHED: measured $%.2f ≥ cap $%.2f — stopping after %d rows.', $spent, $budget, count($rows)));
                        break 3;
                    }
                }
            }
        }
        $this->aborted = $aborted;

        return $this->option('json') ? $this->reportJson($rows, $path, $engines, $repeat) : $this->report($rows, $path, $engines, $repeat);
    }

    /**
     * @param  array<string,mixed>  $case
     * @return array<string,mixed>
     */
    private function runCase(AnswerEngineDispatcher $dispatcher, array $case, string $engine, bool $persist, int $repeatIndex): array
    {
        ['employee' => $employee, 'note' => $note] = $this->resolveEmployee($case);
        if ($employee === null) {
            return [
                'id' => $case['id'], 'engine' => $engine, 'repeat' => $repeatIndex,
                'skipped' => true, 'note' => $note, 'pass' => false,
                'must_not_answer_violated' => false,
            ];
        }

        $canPersist = $persist && str_starts_with($employee->email, 'test-') && str_ends_with($employee->email, '@example.com');

        // Default no writes (plan §E.14's own bullet): every case runs inside a
        // transaction rolled back at the end UNLESS --persist AND the resolved
        // employee is a test-*@example.com account, same gate `EstatutoGoldEval`
        // already enforces. `DB::transaction()` re-throws on an exception (it
        // would roll back either way), so a plain begin/execute/rollback — not
        // "throw to force rollback" — is what actually keeps a passing case's
        // row when writes are discarded.
        if ($canPersist) {
            $row = $this->executeOne($dispatcher, $employee, $case, $engine, $note);
        } else {
            DB::beginTransaction();
            try {
                $row = $this->executeOne($dispatcher, $employee, $case, $engine, $note);
            } finally {
                DB::rollBack();
            }
        }

        return ['repeat' => $repeatIndex, ...$row];
    }

    /**
     * @param  array<string,mixed>  $case
     * @return array<string,mixed>
     */
    private function executeOne(AnswerEngineDispatcher $dispatcher, Employee $employee, array $case, string $engine, ?string $note): array
    {
        $session = ChatSession::create([
            'employee_id' => $employee->id, 'started_at' => now(), 'last_activity_at' => now(),
        ]);

        $startedAt = microtime(true);
        $result = $dispatcher->handle($employee, $case['question'], $session->uuid, null, $engine);
        $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);

        $message = ChatMessage::where('session_id', $session->id)->where('role', 'assistant')->orderByDesc('id')->first();
        $trace = $message?->trace?->trace ?? $result['trace'] ?? [];

        // Informational, like cost: wall-clock for the whole turn, and where to
        // find it afterwards (Historial, when `--persist`).
        return $this->scoreCase($case, $engine, $result, $trace, $note) + [
            'latency_ms' => $latencyMs,
            'session_uuid' => $session->uuid,
            'message_id' => $message?->id,
        ];
    }

    /**
     * @param  array<string,mixed>  $case
     * @param  array<string,mixed>  $result
     * @param  array<string,mixed>  $trace
     * @return array<string,mixed>
     */
    private function scoreCase(array $case, string $engine, array $result, array $trace, ?string $note): array
    {
        $expect = $case['expect'];
        $outcome = $result['outcome'] ?? '?';
        $reason = $result['escalation_reason'] ?? null;
        $floor = $trace['floor_decision'] ?? [];
        $path = $floor['path'] ?? null;
        $authority = $floor['authority_used'] ?? [];
        $answer = (string) ($result['answer'] ?? '');

        $mustNotAnswer = (bool) ($expect['must_not_answer'] ?? false) || (($expect['outcome'] ?? null) === 'escalate');
        $violatedHard = $mustNotAnswer && $outcome === 'answer';
        $hardKind = $violatedHard ? 'false_answer' : null;

        // Lane hard gate (plan §E.14 set 1): a `forbid_paths` case must never END
        // as an answer on that path (a lane answer on an entitlement question).
        $laneAnswer = $outcome === 'answer' && $path === 'general_knowledge';
        $forbidPaths = is_array($expect['forbid_paths'] ?? null) ? $expect['forbid_paths'] : [];
        if ($outcome === 'answer' && $path !== null && in_array($path, $forbidPaths, true)) {
            $violatedHard = true;
            $hardKind = $hardKind ?? 'forbidden_path_answer';
        }

        // Lane invariants, EVERY case: a surfaced lane answer is web-sourced (§B.6.4)
        // and carries no digit at all (the post-check's F1) — checked end to end on the
        // text the employee would read, independent of the rule that should have enforced it.
        if ($laneAnswer) {
            $webSources = array_filter($trace['general_lane']['sources'] ?? [], fn ($s) => is_array($s) && ($s['kind'] ?? null) === 'web');
            if ($webSources === []) {
                $violatedHard = true;
                $hardKind = $hardKind ?? 'lane_answer_without_web_source';
            } elseif (preg_match('/\d/u', $answer) === 1) {
                $violatedHard = true;
                $hardKind = $hardKind ?? 'lane_answer_has_digit';
            }
        }

        // Whitelist hard gate (plan §E.14 set 2, EVERY case): an `ask` that reaches
        // the employee must never be about a Directory-owned field. The pre-call
        // rule guarantees it; this proves it end to end on the text the employee sees.
        $forbiddenAsk = false;
        if ($outcome === 'ask') {
            foreach (AskEmployeeWhitelist::FORBIDDEN_FIELDS as $patterns) {
                foreach ($patterns as $pattern) {
                    if (@preg_match($pattern, $answer) === 1) {
                        $forbiddenAsk = true;
                    }
                }
            }
            if ($forbiddenAsk) {
                $violatedHard = true;
                $hardKind = $hardKind ?? 'forbidden_ask_reached';
            }
        }

        $pass = true;
        if (isset($expect['outcome']) && $expect['outcome'] !== null) {
            $pass = $pass && $outcome === $expect['outcome'];
        }
        if (isset($expect['reason']) && $expect['reason'] !== null) {
            $pass = $pass && $reason === $expect['reason'];
        }
        if (isset($expect['path']) && is_array($expect['path']) && $expect['path'] !== []) {
            $pass = $pass && in_array($path, $expect['path'], true);
        }
        if (isset($expect['path_not']) && is_array($expect['path_not'])) {
            // Sprint 13b situational cases: a bare fact quote is the wrong route; any other route is acceptable.
            $pass = $pass && ! ($outcome === 'answer' && $path !== null && in_array($path, $expect['path_not'], true));
        }
        if (isset($expect['value_contains']) && is_array($expect['value_contains'])) {
            foreach ($expect['value_contains'] as $needle) {
                $pass = $pass && str_contains(mb_strtolower($answer), mb_strtolower((string) $needle));
            }
        }
        if (isset($expect['value_not_contains']) && is_array($expect['value_not_contains'])) {
            foreach ($expect['value_not_contains'] as $needle) {
                $pass = $pass && ! str_contains(mb_strtolower($answer), mb_strtolower((string) $needle));
            }
        }
        if (isset($expect['outcome_in']) && is_array($expect['outcome_in'])) {
            $pass = $pass && in_array($outcome, $expect['outcome_in'], true);
        }
        // pass-with-caveat (CP-2, wt-01/wt-05): the fact is convenio-wide, so an ANSWER is fine
        // — but only if it says the asserted attribute does not change it / cannot be confirmed.
        // An escalation passes as is. `caveat_ok` is reported per row.
        $caveatOk = null;
        if (isset($expect['caveat_any']) && is_array($expect['caveat_any']) && $outcome === 'answer') {
            $caveatOk = false;
            foreach ($expect['caveat_any'] as $needle) {
                if (str_contains(mb_strtolower($answer), mb_strtolower((string) $needle))) {
                    $caveatOk = true;
                }
            }
            $pass = $pass && $caveatOk;
        }
        // any_of: [{path:[..], value_contains:[..]}, {reason:'..'}, {outcome:'..'}] — pass if ONE
        // alternative holds (e.g. "the fact path answered it, or it escalated as a coverage gap").
        if (isset($expect['any_of']) && is_array($expect['any_of'])) {
            $anyOk = false;
            foreach ($expect['any_of'] as $alt) {
                $ok = true;
                if (isset($alt['outcome'])) {
                    $ok = $ok && $outcome === $alt['outcome'];
                }
                if (isset($alt['reason'])) {
                    $ok = $ok && $reason === $alt['reason'];
                }
                if (isset($alt['path'])) {
                    $ok = $ok && in_array($path, (array) $alt['path'], true);
                }
                foreach ((array) ($alt['value_contains'] ?? []) as $needle) {
                    $ok = $ok && str_contains(mb_strtolower($answer), mb_strtolower((string) $needle));
                }
                $anyOk = $anyOk || $ok;
            }
            $pass = $pass && $anyOk;
        }
        // Slice 13d (ADR-0037): complementary fact sets. `expect.fact_set` = {facts_selected:[ids] (set-equal),
        // cited_min:int, cited_all:bool}. The cited/offered checks apply only when a composition offered facts
        // (Phase 1 quotes carry no composition block); "every cited ∈ offered" is always checked independently.
        $factTrace = $this->factSetRow($trace);
        $factSetOk = null;
        if (isset($expect['fact_set']) && is_array($expect['fact_set'])) {
            $fe = $expect['fact_set'];
            if ($fe['absent'] ?? false) {
                // A precedence case (a group fact won outright): no set may have been formed or offered.
                $factSetOk = $factTrace === null || ($factTrace['facts_selected'] === [] && $factTrace['fact_ids_offered'] === []);
            } elseif ($factTrace === null) {
                $factSetOk = false;
            } else {
                $factSetOk = true;
                if (isset($fe['selected_count'])) {
                    $factSetOk = count($factTrace['facts_selected']) === (int) $fe['selected_count'];
                }
                if ($factSetOk && isset($fe['facts_selected'])) {
                    $want = array_map('intval', (array) $fe['facts_selected']);
                    $got = $factTrace['facts_selected'];
                    sort($want);
                    sort($got);
                    $factSetOk = $want === $got;
                }
                if ($factSetOk && $factTrace['fact_ids_offered'] !== []) {
                    $offered = $factTrace['fact_ids_offered'];
                    $cited = $factTrace['fact_ids_cited'];
                    $factSetOk = array_diff($cited, $offered) === [];
                    if ($factSetOk && isset($fe['cited_min'])) {
                        $factSetOk = count($cited) >= (int) $fe['cited_min'];
                    }
                    if ($factSetOk && ($fe['cited_all'] ?? false)) {
                        $factSetOk = array_diff($offered, $cited) === [];
                    }
                }
            }
            $pass = $pass && $factSetOk;
        }
        if ($violatedHard) {
            $pass = false;
        }

        // ---- agent routing (trace.agent) --------------------------------------
        // first_tool = the FIRST TOOL THE PLANNER PROPOSED (or the round-0 seeded
        // tool): the routing signal descriptions are tuned against. A proposal a
        // rule denied still counts — that is exactly the "planner tried the wrong
        // tool first" lag the descriptions must fix. `first_tool_executed` is the
        // first tool that actually ran.
        $steps = $trace['agent']['steps'] ?? [];
        $firstTool = null;
        $firstExecuted = null;
        $lastStepType = null;
        $verdicts = 0;
        $corrections = 0;
        $forcedTerminations = 0;
        $normRejections = 0;
        $askAttempted = false;
        $askDenied = false;
        foreach ($steps as $step) {
            $type = $step['type'] ?? null;
            if ($firstTool === null && $type === 'round0') {
                $firstTool = (string) (($step['seeded'][0] ?? null) ?: 'round0');
            }
            if ($firstTool === null && $type === 'planner_round') {
                // Sprint 13b: `normalize_question` is a control call about the question, not a routing choice — the
                // first tool is the first call that is NOT it (found at S1: it made first-tool accuracy read 0/20).
                foreach ($step['calls'] ?? [] as $c) {
                    if (($c['tool'] ?? null) !== 'normalize_question') {
                        $firstTool = $c['tool'] ?? null;
                        break;
                    }
                }
            }
            if ($firstTool === null && $type === 'round1a') {
                $firstTool = (string) (($step['seeded'][0] ?? null) ?: 'round1a');
            }
            if ($firstExecuted === null && $type === 'tool_call') {
                $firstExecuted = $step['tool'] ?? null;
            }
            if ($type === 'planner_round') {
                foreach ($step['calls'] ?? [] as $c) {
                    if (($c['tool'] ?? null) === 'ask_employee') {
                        $askAttempted = true;
                    }
                }
            }
            if ($type === 'tool_denied' && ($step['tool'] ?? null) === 'ask_employee') {
                $askDenied = true;
            }
            if ($type === 'rule_verdict') {
                $verdicts++;
                $v = (string) ($step['verdict'] ?? '');
                $ruleId = (string) ($step['rule'] ?? '');
                if ($ruleId === 'normalization_validation') {
                    // Sprint 13b: a rejected normalization is not a routing miss — counted under `norm`, not corrections.
                    $normRejections++;
                    $verdicts--;

                    continue;
                }
                // A CORRECTION = a rule rejected/rewrote the planner's proposal, or forced
                // a terminal action outside a tool's own mandated post-call termination.
                // `*_post_call` force_* verdicts are the mechanical end of a tool (e.g. the
                // prose tool always ends the turn); counted separately, not as a routing miss.
                if (in_array($v, ['deny', 'rewrite'], true)) {
                    $corrections++;
                } elseif (str_starts_with($v, 'force_')) {
                    if (str_ends_with($ruleId, '_post_call')) {
                        $forcedTerminations++;
                    } else {
                        $corrections++;
                    }
                }
            }
            $lastStepType = $type ?? $lastStepType;
        }
        $terminal = $trace['agent']['termination'] ?? $lastStepType;

        [$tokensPrompt, $tokensCompletion, $costUsd] = $this->costFromTrace($trace);

        return [
            'id' => $case['id'],
            'class' => $case['class'] ?? null,
            'engine' => $engine,
            'skipped' => false,
            'note' => $note,
            'outcome' => $outcome,
            'expected_outcome' => $expect['outcome'] ?? null,
            'reason' => $reason,
            'path' => $path,
            'authority' => $authority,
            'pass' => $pass,
            'must_not_answer_violated' => $violatedHard,
            'hard_kind' => $hardKind,
            'lane_answer' => $laneAnswer,
            'caveat_ok' => $caveatOk,
            'answer_excerpt' => mb_substr($answer, 0, 700),
            'forbidden_ask_reached' => $forbiddenAsk,
            'ask_attempted' => $askAttempted,
            'ask_denied' => $askDenied,
            'first_tool' => $firstTool,
            'first_tool_executed' => $firstExecuted,
            'expected_first_tool' => $expect['first_tool'] ?? null,
            'terminal' => $terminal,
            'expected_terminal' => $expect['terminal'] ?? null,
            'rule_verdicts' => $verdicts,
            'rule_overrides' => $corrections,
            'forced_terminations' => $forcedTerminations,
            'tokens_prompt' => $tokensPrompt,
            'tokens_completion' => $tokensCompletion,
            'cost_usd' => $costUsd,
            'bank' => $case['bank'] ?? null,
            'anchored' => $case['anchored'] ?? null,
            'phrasing' => $case['phrasing'] ?? null,
            'situational' => $case['situational'] ?? false,
            'norm' => $this->normRow($trace),
            'fact_trace' => $factTrace,
            'fact_set_ok' => $factSetOk,
        ];
    }

    /**
     * Slice 13d — the complementary-set facts of one turn, wherever the engine nested the reference-fact trace
     * (classic: trace.reference_fact / trace.composition; agent: inside the tool step). Null when no set was formed
     * and no composition offered a fact id.
     *
     * @param  array<string,mixed>  $trace
     * @return array{composition:?string, facts_selected:list<int>, fact_ids_offered:list<int>, fact_ids_cited:list<int>, conflict_fact_id:?int, validity_selection:?string}|null
     */
    private function factSetRow(array $trace): ?array
    {
        $found = ['fact_set' => null, 'fact_ids_offered' => null, 'fact_ids_cited' => null, 'conflict' => null, 'validity_selection' => null];
        $walk = function ($node) use (&$walk, &$found) {
            if (! is_array($node)) {
                return;
            }
            foreach ($node as $k => $v) {
                if (is_string($k) && array_key_exists($k, $found) && $found[$k] === null && $v !== null) {
                    if ($k === 'conflict' && ! (is_array($v) && array_key_exists('fact_id', $v))) {
                        continue;
                    }
                    $found[$k] = $v;
                }
                $walk($v);
            }
        };
        $walk($trace);

        $ints = fn ($v) => is_array($v) ? array_values(array_map('intval', $v)) : [];
        $fs = is_array($found['fact_set']) ? $found['fact_set'] : null;
        if ($fs === null && $found['fact_ids_offered'] === null) {
            return null;
        }

        return [
            'composition' => $fs['composition'] ?? null,
            'facts_selected' => $ints($fs['facts_selected'] ?? []),
            'fact_ids_offered' => $ints($found['fact_ids_offered']),
            'fact_ids_cited' => $ints($found['fact_ids_cited']),
            'conflict_fact_id' => isset($found['conflict']['fact_id']) ? (int) $found['conflict']['fact_id'] : null,
            'validity_selection' => is_string($found['validity_selection']) ? $found['validity_selection'] : null,
        ];
    }

    /**
     * Sprint 13b — the per-turn normalization facts the gate aggregates (plan.md §7.3), from `trace.agent.normalization`.
     *
     * @param  array<string,mixed>  $trace
     * @return array<string,mixed>|null null when the turn had no normalization block (classic, Round 0, flag off)
     */
    private function normRow(array $trace): ?array
    {
        $n = $trace['agent']['normalization'] ?? null;
        if (! is_array($n)) {
            return null;
        }
        $consumers = is_array($n['consumers'] ?? null) ? $n['consumers'] : [];

        return [
            'verdict' => $n['verdict'] ?? null,
            'topic_id' => $n['used']['topic_id'] ?? null,
            'canonical' => $n['used']['canonical_query'] ?? ($n['proposed']['canonical_query'] ?? null),
            'confidence' => $n['proposed']['confidence'] ?? null,
            'rejected_by' => array_values(array_unique(array_column($n['rejections'] ?? [], 'rule'))),
            'topic_dropped' => (bool) ($n['topic_dropped'] ?? false),
            'round1a_ran' => (bool) ($n['round1a']['ran'] ?? false),
            'round1a_skipped' => $n['round1a']['skipped'] ?? null,
            'consumed' => count($consumers),
            'check_a_rescued' => (bool) count(array_filter($consumers, fn ($c) => $c['check_a_rescued'] ?? false)),
            'rescued_answer' => (bool) count(array_filter($consumers, fn ($c) => $c['rescued_answer'] ?? false)),
            'literal_top_score' => $consumers[0]['literal_top_score'] ?? null,
            'canonical_top_score' => $consumers[0]['canonical_top_score'] ?? null,
            'planner_prompt_version' => $n['planner_prompt_version'] ?? null,
            'literal' => $n['literal'] ?? null,
        ];
    }

    /**
     * Sprint 13b — null when the set is not a frozen bank (or is unchanged); an error string when its sha256
     * differs from the MANIFEST.sha256 sitting next to it.
     */
    private function frozenBankCheck(string $path): ?string
    {
        $manifest = dirname($path).'/MANIFEST.sha256';
        if (! is_file($manifest)) {
            return null;
        }
        $base = basename($path);
        foreach (preg_split('/\R/', (string) file_get_contents($manifest)) ?: [] as $line) {
            if (preg_match('/^([0-9a-f]{64})\s+\*?(.+)$/', trim($line), $m) === 1 && trim($m[2]) === $base) {
                $actual = hash_file('sha256', $path);

                return $actual === $m[1] ? null : "FROZEN BANK CHANGED: {$base} sha256 {$actual} != MANIFEST {$m[1]}. Refusing to run (pass --allow-unfrozen only for a non-gate probe).";
            }
        }

        return null;
    }

    /** Recursively sums every `trace_fragment{prompt_tokens,completion_tokens,model}` and `agent.steps[].tokens` found, priced from {@see PRICE_PER_MTOK}. Informational (plan §E.14's own "cost/latency informational"). @return array{0:int,1:int,2:float} */
    private function costFromTrace(array $trace): array
    {
        $promptTotal = 0;
        $completionTotal = 0;
        $costTotal = 0.0;
        $plannerModel = $trace['agent']['planner']['model'] ?? null;

        $walk = function ($node, ?string $modelHint) use (&$walk, &$promptTotal, &$completionTotal, &$costTotal) {
            if (! is_array($node)) {
                return;
            }
            if (array_key_exists('prompt_tokens', $node) || array_key_exists('completion_tokens', $node)) {
                $p = (int) ($node['prompt_tokens'] ?? 0);
                $c = (int) ($node['completion_tokens'] ?? 0);
                $model = $node['model'] ?? $modelHint;
                [$in, $out] = self::PRICE_PER_MTOK[$model] ?? self::DEFAULT_PRICE;
                $promptTotal += $p;
                $completionTotal += $c;
                $costTotal += ($p / 1_000_000) * $in + ($c / 1_000_000) * $out;
            }
            if (isset($node['tokens']) && is_array($node['tokens'])) {
                $p = (int) ($node['tokens']['prompt'] ?? 0);
                $c = (int) ($node['tokens']['completion'] ?? 0);
                $model = $node['model'] ?? $modelHint;
                [$in, $out] = self::PRICE_PER_MTOK[$model] ?? self::DEFAULT_PRICE;
                $promptTotal += $p;
                $completionTotal += $c;
                $costTotal += ($p / 1_000_000) * $in + ($c / 1_000_000) * $out;
            }
            foreach ($node as $v) {
                if (is_array($v)) {
                    $walk($v, $modelHint);
                }
            }
        };

        $walk($trace, $plannerModel);

        return [$promptTotal, $completionTotal, round($costTotal, 6)];
    }

    /** Nearest-rank percentile of a numeric list (informational latency). @param list<int|float> $values */
    private function percentile(array $values, float $p): ?int
    {
        if ($values === []) {
            return null;
        }
        sort($values);
        $rank = (int) ceil($p / 100 * count($values));

        return (int) $values[max(0, min(count($values) - 1, $rank - 1))];
    }

    /**
     * One summary shape for the text and the JSON report: per engine totals,
     * per class, and per case (k/n across repeats, side by side across engines).
     *
     * @param  list<array<string,mixed>>  $rows
     * @param  list<string>  $engines
     * @return array<string,mixed>
     */
    private function summarize(array $rows, array $engines): array
    {
        $summary = ['engines' => [], 'cases' => []];

        foreach ($engines as $engine) {
            $scored = array_values(array_filter($rows, fn ($r) => ! $r['skipped'] && $r['engine'] === $engine));
            if ($scored === []) {
                continue;
            }
            $n = count($scored);
            $answers = count(array_filter($scored, fn ($r) => $r['outcome'] === 'answer'));
            $pathDist = [];
            $authorityDist = [];
            $outcomeDist = [];
            foreach ($scored as $r) {
                $label = $r['path'] ?? ($r['authority'] ? 'prose' : '(none)');
                $pathDist[$label] = ($pathDist[$label] ?? 0) + 1;
                $key = $r['authority'] ? implode('+', $r['authority']) : '(none)';
                $authorityDist[$key] = ($authorityDist[$key] ?? 0) + 1;
                $outcomeDist[$r['outcome']] = ($outcomeDist[$r['outcome']] ?? 0) + 1;
            }
            $ftScored = array_values(array_filter($scored, fn ($r) => $r['expected_first_tool'] !== null));
            $termScored = array_values(array_filter($scored, fn ($r) => $r['expected_terminal'] !== null));
            $lat = array_values(array_filter(array_column($scored, 'latency_ms'), 'is_int'));
            $cost = array_sum(array_column($scored, 'cost_usd'));

            $byClass = [];
            foreach ($scored as $r) {
                $c = $r['class'] ?? '(none)';
                $byClass[$c]['n'] = ($byClass[$c]['n'] ?? 0) + 1;
                $byClass[$c]['pass'] = ($byClass[$c]['pass'] ?? 0) + ($r['pass'] ? 1 : 0);
                $byClass[$c]['hard'] = ($byClass[$c]['hard'] ?? 0) + ($r['must_not_answer_violated'] ? 1 : 0);
                $byClass[$c]['answers'] = ($byClass[$c]['answers'] ?? 0) + ($r['outcome'] === 'answer' ? 1 : 0);
                $byClass[$c]['lane_answers'] = ($byClass[$c]['lane_answers'] ?? 0) + (($r['lane_answer'] ?? false) ? 1 : 0);
            }

            $summary['engines'][$engine] = [
                'norm' => $this->normSummary($scored),
                'by_phrasing_anchor' => $this->byPhrasingAnchor($scored),
                'unanchored_breakdown' => $this->unanchoredBreakdown($scored),
                'turns' => $n,
                'pass' => count(array_filter($scored, fn ($r) => $r['pass'])),
                'hard_violations' => count(array_filter($scored, fn ($r) => $r['must_not_answer_violated'])),
                'hard_kinds' => array_count_values(array_filter(array_column($scored, 'hard_kind'))),
                'answers' => $answers,
                'outcomes' => $outcomeDist,
                'paths' => $pathDist,
                'authority' => $authorityDist,
                'lane_answers' => count(array_filter($scored, fn ($r) => $r['lane_answer'] ?? false)),
                'forbidden_asks_reached' => count(array_filter($scored, fn ($r) => $r['forbidden_ask_reached'] ?? false)),
                'asks_attempted' => count(array_filter($scored, fn ($r) => $r['ask_attempted'] ?? false)),
                'asks_denied' => count(array_filter($scored, fn ($r) => $r['ask_denied'] ?? false)),
                'first_tool' => ['correct' => count(array_filter($ftScored, fn ($r) => in_array($r['first_tool'], (array) $r['expected_first_tool'], true))), 'of' => count($ftScored)],
                'terminal' => ['correct' => count(array_filter($termScored, fn ($r) => $r['terminal'] === $r['expected_terminal'])), 'of' => count($termScored)],
                'corrections_per_100' => round(array_sum(array_column($scored, 'rule_overrides')) / $n * 100, 1),
                'forced_terminations_per_100' => round(array_sum(array_column($scored, 'forced_terminations')) / $n * 100, 1),
                'cost_usd' => round($cost, 4),
                'cost_per_turn_usd' => round($cost / $n, 5),
                'cost_per_answer_usd' => $answers > 0 ? round($cost / $answers, 5) : null,
                'latency_ms' => ['p50' => $this->percentile($lat, 50), 'p95' => $this->percentile($lat, 95), 'n' => count($lat)],
                'by_class' => $byClass,
            ];
        }

        // Per case: k/n per engine, plus stability (distinct outcomes across repeats).
        $byCase = [];
        foreach ($rows as $r) {
            $id = $r['id'];
            $e = $r['engine'];
            $byCase[$id][$e]['n'] = ($byCase[$id][$e]['n'] ?? 0) + 1;
            $byCase[$id][$e]['pass'] = ($byCase[$id][$e]['pass'] ?? 0) + (($r['pass'] ?? false) ? 1 : 0);
            $byCase[$id][$e]['sigs'][] = ($r['skipped'] ?? false) ? 'skipped' : ($r['outcome'].'/'.($r['path'] ?? '-').'/'.($r['reason'] ?? '-'));
            $byCase[$id]['class'] = $r['class'] ?? null;
        }
        foreach ($byCase as $id => $perEngine) {
            $entry = ['id' => $id, 'class' => $perEngine['class']];
            foreach ($engines as $e) {
                if (isset($perEngine[$e])) {
                    $entry[$e] = [
                        'pass' => $perEngine[$e]['pass'], 'n' => $perEngine[$e]['n'],
                        'signatures' => array_values(array_unique($perEngine[$e]['sigs'])),
                    ];
                }
            }
            $summary['cases'][] = $entry;
        }

        return $summary;
    }

    /**
     * Sprint 13b — verdict counts, Round 1a, rescues LISTED (id + literal + canonical), rejection-rule histogram.
     *
     * @param  list<array<string,mixed>>  $scored
     * @return array<string,mixed>
     */
    private function normSummary(array $scored): array
    {
        $withNorm = array_values(array_filter($scored, fn ($r) => is_array($r['norm'] ?? null)));
        $verdicts = array_count_values(array_map(fn ($r) => (string) $r['norm']['verdict'], $withNorm));
        $rejectedBy = [];
        foreach ($withNorm as $r) {
            foreach ($r['norm']['rejected_by'] as $rule) {
                $rejectedBy[$rule] = ($rejectedBy[$rule] ?? 0) + 1;
            }
        }
        $rescues = [];
        foreach ($withNorm as $r) {
            if ($r['norm']['rescued_answer']) {
                $rescues[] = ['id' => $r['id'], 'literal' => $r['norm']['literal'], 'canonical' => $r['norm']['canonical'], 'topic_id' => $r['norm']['topic_id']];
            }
        }

        return [
            'turns_with_block' => count($withNorm),
            'verdicts' => $verdicts,
            'rejected_by' => $rejectedBy,
            'topic_dropped' => count(array_filter($withNorm, fn ($r) => $r['norm']['topic_dropped'])),
            'round1a_ran' => count(array_filter($withNorm, fn ($r) => $r['norm']['round1a_ran'])),
            'check_a_rescues' => count(array_filter($withNorm, fn ($r) => $r['norm']['check_a_rescued'])),
            'rescued_answers' => count($rescues),
            'rescued_answers_listed' => $rescues,
        ];
    }

    /**
     * The 2×2 (authored phrasing × lexicon-anchored) plus G1's own cell: unanchored, non-situational lookups.
     *
     * @param  list<array<string,mixed>>  $scored
     * @return array<string,mixed>
     */
    private function byPhrasingAnchor(array $scored): array
    {
        $cells = [];
        foreach ($scored as $r) {
            if ($r['phrasing'] === null) {
                continue;
            }
            // A fixture with no `anchored` label (the Sprint 13 sets) still gets its phrasing cell, as `unlabelled`.
            $anchor = $r['anchored'] === null ? 'unlabelled' : ($r['anchored'] ? 'anchored' : 'unanchored');
            $key = $r['phrasing'].'|'.$anchor.($r['situational'] ? '|situational' : '');
            $cells[$key]['n'] = ($cells[$key]['n'] ?? 0) + 1;
            $cells[$key]['pass'] = ($cells[$key]['pass'] ?? 0) + ($r['pass'] ? 1 : 0);
        }
        ksort($cells);

        return $cells;
    }

    /** Sprint 13b (S1 decision B): a convenio-wide answered rate below this is a STOP condition, judged at every stage. */
    public const CONVENIO_WIDE_STOP_RATE = 0.5;

    /**
     * G1's own population — colloquial, lexicon-UNanchored — split by class, never blended:
     * `convenio_wide` (a fact that needs no group: answered = pass), `group_unbound` (the honest outcome is the
     * coverage-gap escalation: pass = reached it), `situational` (informational only; a bare fact quote is wrong).
     * `unanchored_overall` = convenio_wide + group_unbound (+ any other class), without situational.
     * `stop_condition` is set when convenio_wide's pass rate < CONVENIO_WIDE_STOP_RATE.
     *
     * @param  list<array<string,mixed>>  $scored
     * @return array<string,mixed> [] when the run has no colloquial-unanchored rows
     */
    private function unanchoredBreakdown(array $scored): array
    {
        $groups = ['convenio_wide' => [], 'group_unbound' => [], 'other' => [], 'situational' => []];
        foreach ($scored as $r) {
            if (($r['phrasing'] ?? null) !== 'colloquial' || ($r['anchored'] ?? null) !== false) {
                continue;
            }
            $g = ($r['situational'] ?? false) ? 'situational'
                : match ($r['class'] ?? null) {
                    'fact_convenio_wide' => 'convenio_wide',
                    'fact_group_labelled_unbound' => 'group_unbound',
                    default => 'other',
                };
            $groups[$g][] = $r;
        }
        $cell = static function (array $rows): array {
            $n = count($rows);
            $pass = count(array_filter($rows, fn ($r) => $r['pass']));

            return [
                'n' => $n, 'pass' => $pass, 'rate' => $n > 0 ? round($pass / $n, 3) : null,
                'answered' => count(array_filter($rows, fn ($r) => $r['outcome'] === 'answer')),
                'hard' => count(array_filter($rows, fn ($r) => $r['must_not_answer_violated'])),
            ];
        };
        $overallRows = array_merge($groups['convenio_wide'], $groups['group_unbound'], $groups['other']);
        if ($overallRows === [] && $groups['situational'] === []) {
            return [];
        }
        $out = [
            'unanchored_overall' => $cell($overallRows),
            'convenio_wide' => $cell($groups['convenio_wide']),
            'group_unbound' => $cell($groups['group_unbound']),
            'situational_informational' => $cell($groups['situational']),
            'stop_condition' => null,
        ];
        if ($out['convenio_wide']['n'] > 0 && $out['convenio_wide']['pass'] / $out['convenio_wide']['n'] < self::CONVENIO_WIDE_STOP_RATE) {
            $out['stop_condition'] = sprintf('convenio-wide %d/%d = %.0f%% < %.0f%%', $out['convenio_wide']['pass'], $out['convenio_wide']['n'], 100 * $out['convenio_wide']['pass'] / $out['convenio_wide']['n'], 100 * self::CONVENIO_WIDE_STOP_RATE);
        }
        if ($groups['other'] !== []) {
            $out['other'] = $cell($groups['other']);
        }

        return $out;
    }

    /** @param  list<array<string,mixed>>  $rows */
    private function report(array $rows, string $path, array $engines, int $repeat): int
    {
        $this->info("answer:gate — {$path} — engine(s): ".implode(',', $engines)." — repeat={$repeat}");
        $this->newLine();

        foreach ($rows as $r) {
            if ($r['skipped']) {
                $this->line("  [{$r['id']}] <comment>{$r['engine']}</comment> <comment>SKIPPED</comment> — {$r['note']}");

                continue;
            }
            $mark = $r['pass'] ? '<info>PASS</info>' : '<error>FAIL</error>';
            $hard = $r['must_not_answer_violated']
                ? ($r['hard_kind'] === 'false_answer' ? ' <error>FALSE ANSWER ON MUST-ESCALATE CASE</error>' : " <error>HARD: {$r['hard_kind']}</error>")
                : '';
            $this->line("  [{$r['id']}] {$r['engine']} {$mark}{$hard} outcome={$r['outcome']} path=".($r['path'] ?? '—').' reason='.($r['reason'] ?? '—').' first_tool='.($r['first_tool'] ?? '—'));
            if ($r['note']) {
                $this->line('        note: '.$r['note']);
            }
            if (! empty($r['fact_trace'])) {
                $ft = $r['fact_trace'];
                $this->line('        facts: selected=['.implode(',', $ft['facts_selected']).'] offered=['.implode(',', $ft['fact_ids_offered']).'] cited=['.implode(',', $ft['fact_ids_cited']).']'
                    .($r['fact_set_ok'] === null ? '' : ' fact_set_ok='.($r['fact_set_ok'] ? 'yes' : 'NO')));
            }
        }

        $this->newLine();
        $summary = $this->summarize($rows, $engines);
        foreach ($summary['engines'] as $engine => $e) {
            $this->info("  Engine: {$engine}");
            $this->line(sprintf('    pass rate: %d/%d', $e['pass'], $e['turns']));
            $this->line(sprintf('    false answers on must-escalate cases (hard, must be 0): %d', $e['hard_kinds']['false_answer'] ?? 0));
            $this->line(sprintf('    all HARD violations (must be 0): %d %s', $e['hard_violations'], json_encode($e['hard_kinds'])));
            $this->line('    outcomes: '.json_encode($e['outcomes'], JSON_UNESCAPED_UNICODE));
            $this->line('    path distribution: '.json_encode($e['paths'], JSON_UNESCAPED_UNICODE));
            $this->line('    authority distribution: '.json_encode($e['authority'], JSON_UNESCAPED_UNICODE));
            $this->line(sprintf('    lane answers: %d — forbidden asks reached: %d — asks attempted/denied: %d/%d', $e['lane_answers'], $e['forbidden_asks_reached'], $e['asks_attempted'], $e['asks_denied']));
            if ($engine === 'agent') {
                $this->line(sprintf('    first-tool accuracy: %d/%d', $e['first_tool']['correct'], $e['first_tool']['of']));
                $this->line(sprintf('    terminal-action accuracy: %d/%d', $e['terminal']['correct'], $e['terminal']['of']));
                $this->line(sprintf('    rule corrections per 100 turns: %.1f (mandated post-call terminations per 100: %.1f)', $e['corrections_per_100'], $e['forced_terminations_per_100']));
            }
            $this->line(sprintf('    latency p50/p95: %s / %s ms', $e['latency_ms']['p50'] ?? '—', $e['latency_ms']['p95'] ?? '—'));
            $this->line(sprintf('    cost (list pricing, informational): $%.4f total, $%.5f/turn, $%s/answer', $e['cost_usd'], $e['cost_per_turn_usd'], $e['cost_per_answer_usd'] ?? '—'));
            $this->line('    by class: '.json_encode($e['by_class'], JSON_UNESCAPED_UNICODE));
            if ($e['by_phrasing_anchor'] !== []) {
                $this->line('    by phrasing|anchored: '.json_encode($e['by_phrasing_anchor'], JSON_UNESCAPED_UNICODE));
            }
            if ($e['unanchored_breakdown'] !== []) {
                $u = $e['unanchored_breakdown'];
                $fmt = fn (array $c) => $c['n'] === 0 ? '—' : sprintf('%d/%d = %.0f%%', $c['pass'], $c['n'], 100 * $c['pass'] / $c['n']);
                $this->line(sprintf(
                    '    colloquial-unanchored (G1): overall %s | convenio-wide %s | group-unbound %s | situational (informational) %s',
                    $fmt($u['unanchored_overall']), $fmt($u['convenio_wide']), $fmt($u['group_unbound']), $fmt($u['situational_informational']),
                ));
                if ($u['stop_condition'] !== null) {
                    $this->line("    <error>STOP CONDITION: {$u['stop_condition']} (S1 decision B) — no further spend until diagnosed</error>");
                }
            }
            if ($e['norm']['turns_with_block'] > 0) {
                $this->line('    normalization: '.json_encode(array_diff_key($e['norm'], ['rescued_answers_listed' => 1]), JSON_UNESCAPED_UNICODE));
                foreach ($e['norm']['rescued_answers_listed'] as $rescue) {
                    $this->line("      rescue: [{$rescue['id']}] «{$rescue['literal']}» → «{$rescue['canonical']}»");
                }
            }
            $this->newLine();
        }

        $anyHard = array_filter($rows, fn ($r) => $r['must_not_answer_violated'] ?? false) !== [];

        return $anyHard ? self::FAILURE : (array_filter($rows, fn ($r) => ! $r['skipped'] && ! $r['pass']) === [] ? self::SUCCESS : self::FAILURE);
    }

    /** @param  list<array<string,mixed>>  $rows */
    private function reportJson(array $rows, string $path, array $engines, int $repeat): int
    {
        $anyHard = array_filter($rows, fn ($r) => $r['must_not_answer_violated'] ?? false) !== [];
        $this->line(json_encode([
            'set' => $path, 'engines' => $engines, 'repeat' => $repeat, 'aborted_on_budget' => $this->aborted,
            'summary' => $this->summarize($rows, $engines), 'rows' => $rows,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return $anyHard ? self::FAILURE : (array_filter($rows, fn ($r) => ! ($r['skipped'] ?? false) && ! ($r['pass'] ?? false)) === [] ? self::SUCCESS : self::FAILURE);
    }
}
