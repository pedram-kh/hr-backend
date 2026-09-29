<?php

namespace App\Services\Agent\Tools;

use App\Services\Agent\Rules\SalaryLookupPostCallRule;
use App\Services\Agent\Tool;
use App\Services\Agent\ToolResult;
use App\Services\Agent\TurnState;
use App\Services\Answer\SalaryPath;
use App\Services\RouterService;

/**
 * Sprint 13, build step 5 (plan.md §B.3.1) — thin wrapper over
 * `App\Services\Answer\SalaryPath::handle()`, the SAME class round 0 already
 * calls (§C.9) for the deterministic salary short-circuit. No logic is
 * duplicated here beyond what round 0 already computes: `$decision['cross_path']`
 * from `RouterService::crossPathProseClauses()` and `subqueries` from
 * `deterministicSplit()` — mirroring `ChatService`'s own router-classification
 * shape exactly, so `SalaryPath::handle()` (R07/R08, both already INSIDE that
 * class, unchanged) sees the identical input whether it runs from round 0 or
 * from the planner calling this tool in round 1+.
 *
 * `SalaryPath::handle()` never returns partial material — every outcome
 * (`answer`/`needs_category`/`escalate`) is the WHOLE turn's decision, exactly
 * as classic's salary branch never falls through to prose (ADR-0006/0027: a
 * salary figure is never read from prose). So every result here is
 * `ToolResult::TERMINAL`; {@see SalaryLookupPostCallRule}
 * (registered at `post_call:salary_lookup`) always forces one of the three
 * documented verdicts — this tool's own `run()` never decides the turn by
 * itself, it only produces the `TurnOutcome` for the rule to act on.
 */
final class SalaryLookupTool implements Tool
{
    public function __construct(
        private readonly RouterService $router,
        private readonly SalaryPath $salaryPath,
    ) {}

    public function name(): string
    {
        return 'salary_lookup';
    }

    public function definition(): array
    {
        return [
            'name' => 'salary_lookup',
            // Spanish, verbatim from plan.md §C.8's fixed tool description text.
            'description' => 'Consulta la tabla salarial estructurada del convenio de la persona para '
                .'su categoría y el año vigente. Es la ÚNICA fuente válida para cualquier cifra de salario, '
                .'sueldo, nómina, pagas o precio/hora. Úsala siempre que la pregunta pida una cantidad de '
                .'dinero de su propio salario. No sirve para el SMI ni para cifras de otras personas. '
                .'Si falta la categoría, la herramienta ofrece la lista cerrada de categorías; no la preguntes tú.',
            'input_schema' => ['type' => 'object', 'properties' => [], 'additionalProperties' => false],
        ];
    }

    /**
     * Not a clarification-family tool by itself (§B.4.3's budget rule is
     * generic/tool-agnostic and checked at `pre_call` — before the outcome is
     * known). Whether THIS call turns out to be a `needs_category` pick is
     * only knowable post-call, so the clarification-budget check for salary
     * lives in `SalaryLookupPostCallRule`, not here — gating every
     * `salary_lookup` call at `pre_call` on a budget that has nothing to do
     * with most of them (a plain answer or a coverage gap) would incorrectly
     * block legitimate salary answers once the budget happens to be spent on
     * unrelated `ask_employee` clarifications.
     */
    public function countsAsClarification(): bool
    {
        return false;
    }

    public function run(array $input, TurnState $state): ToolResult
    {
        // Mirrors `RouterService::classify()`'s OWN salary-matching decision
        // construction exactly (`RouterService.php:143-174`) — not a
        // reinvention. Caught by the wrapper-equivalence test: an earlier
        // draft invented its own `source`/`subqueries` shape (`deterministicSplit()`
        // instead of `crossPathProseClauses()`), which diverged from classic's
        // `trace.floor_decision.cross_path` on a real cross-path compound.
        $proseClauses = $this->router->crossPathProseClauses($state->question);
        $decision = $proseClauses !== []
            ? ['cross_path' => true, 'source' => 'deterministic_salary_crosspath', 'subqueries' => $proseClauses]
            : ['cross_path' => false, 'source' => 'deterministic_salary', 'subqueries' => []];

        $outcome = $this->salaryPath->handle(
            $state->employee,
            $state->question,
            $decision,
            $state->asOfDate,
            $state->selectedJobCategoryId,
            $state->trace,
        );

        // planner_summary (§C.10): status only — NEVER the figure itself.
        $summary = match ($outcome->outcome) {
            'answer' => ['status' => 'answer'],
            'needs_category' => ['status' => 'needs_category'],
            default => ['status' => 'escalate'],
        };

        // `traceBlocks` is unused for a TERMINAL result — the post-call rule
        // (below) always forces this outcome, and a forced verdict carries
        // its OWN complete `$outcome->trace` (built by `SalaryPath::handle()`
        // from the full trace it was given), never `runToolCall()`'s
        // MATERIAL-only merge path.
        return new ToolResult(ToolResult::TERMINAL, terminalOutcome: $outcome, plannerSummary: $summary);
    }
}
