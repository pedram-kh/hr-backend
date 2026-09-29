<?php

namespace App\Services\Answer;

use App\Models\Employee;
use App\Services\ChatService;
use App\Services\RouterService;
use App\Services\SalaryAnswerService;
use Illuminate\Support\Carbon;

/**
 * Sprint 13, build step 1 (plan.md §B.1) — `App\Services\Answer\SalaryPath`,
 * extracted VERBATIM from the `RouterService::SALARY` branch of
 * `ChatService::handleMessage()` (`ChatService.php:329-414`, pre-refactor line
 * numbers): the cross-path compound check, the SMI/statutory-figure check,
 * then `SalaryAnswerService::answer()` and its three outcomes (answer /
 * needs_category / coverage-gap escalate). Pure SQL — no hr-ai call.
 */
class SalaryPath
{
    public function __construct(
        private readonly RouterService $router,
        private readonly SalaryAnswerService $salary,
    ) {}

    /**
     * @param  array<string,mixed>  $decision  the router's classification (label=='salary')
     * @param  array<string,mixed>  $trace
     */
    public function handle(Employee $employee, string $question, array $decision, Carbon $asOfDate, ?int $selectedJobCategoryId, array $trace): TurnOutcome
    {
        // Fix 3 (Correction-03): a salary+prose CROSS-PATH compound (the salary
        // pre-classifier matched, but the question also has a clear non-salary
        // clause). The old behaviour short-circuited the whole turn to SQL and
        // silently dropped the prose half. Escalate-with-note instead, so the
        // prose half is surfaced to a human, never silently dropped.
        if ($decision['cross_path'] ?? false) {
            $trace['floor_decision'] = [
                'path' => 'salary_prose_crosspath',
                'outcome' => 'escalate',
                'escalation_reason' => 'low_confidence',
                'cross_path' => [
                    'detected_by' => $decision['source'],
                    'prose_subqueries' => $decision['subqueries'],
                ],
                'note' => 'salary+prose cross-path compound — escalated with note so the prose half is not silently dropped (Correction-03)',
            ];

            return new TurnOutcome('escalate', ChatService::CROSSPATH_MESSAGE, [], $trace, 'low_confidence');
        }

        // Sprint 10b, Correction-01 (post-D1-bis regression, eyes-on found):
        // SMI/salario mínimo names a STATUTORY figure, never a cell in the
        // employee's OWN convenio salary table — `SalaryAnswerService`
        // cannot tell the two apart (it only resolves WHO is asking, never
        // WHAT). D1-bis's gold-eval only exercised the no-tables profile, so
        // it never caught `test-navarra@example.com`-shaped employees (a
        // resolvable table + category) getting her own category cell as a
        // non-responsive answer to a national-figure question. Checked here,
        // BEFORE `$this->salary->answer()` is ever called, so the answer path
        // is unreachable for these regardless of whether a table/category/row
        // exists — the contract is escalation for every employee profile.
        if ($this->router->matchesStatutorySalaryFigure($question)) {
            $trace['salary'] = [
                'outcome' => 'escalate',
                'note' => 'statutory figure (SMI/salario mínimo) — never sourced from the employee\'s own convenio salary table, regardless of whether one exists (Correction-01)',
            ];
            $trace['floor_decision'] = [
                'path' => 'salary_sql',
                'outcome' => 'escalate',
                'escalation_reason' => 'salary_coverage_gap',
                'note' => $trace['salary']['note'],
            ];

            return new TurnOutcome('escalate', ChatService::STATUTORY_SALARY_MESSAGE, [], $trace, 'salary_coverage_gap');
        }

        $result = $this->salary->answer($employee, $asOfDate, $selectedJobCategoryId);
        $trace['salary'] = $result['salary'];

        $outcome = $result['outcome'];
        if ($outcome === SalaryAnswerService::OUTCOME_ANSWER) {
            $trace['floor_decision'] = [
                'path' => 'salary_sql',
                'outcome' => 'answer',
                'escalation_reason' => null,
                'note' => 'exact figure from salary_tables (year '.($result['salary']['year'] ?? '?').')',
            ];

            return new TurnOutcome('answer', $result['answer'], $result['citations'], $trace, null);
        }

        if ($outcome === SalaryAnswerService::OUTCOME_NEEDS_CATEGORY) {
            $trace['floor_decision'] = [
                'path' => 'salary_sql',
                'outcome' => 'needs_category',
                'escalation_reason' => null,
                'note' => 'constrained category pick offered (single-turn, §4)',
            ];

            // A pick is NOT an escalation and NOT an answer → no card, no citations.
            return new TurnOutcome('needs_category', $result['answer'], [], $trace, null, $result['categories']);
        }

        // coverage gap → escalate salary_coverage_gap (supersedes salary_not_in_chat)
        $trace['floor_decision'] = [
            'path' => 'salary_sql',
            'outcome' => 'escalate',
            'escalation_reason' => $result['escalation_reason'] ?? 'salary_coverage_gap',
            'note' => $result['salary']['note'] ?? 'salary coverage gap',
        ];

        return new TurnOutcome('escalate', $result['answer'], [], $trace, $result['escalation_reason'] ?? 'salary_coverage_gap');
    }
}
