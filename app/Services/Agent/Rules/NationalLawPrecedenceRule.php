<?php

namespace App\Services\Agent\Rules;

use App\Services\Agent\Rule;
use App\Services\Agent\ToolResult;
use App\Services\Agent\TurnState;
use App\Services\Agent\Verdict;
use App\Support\CorpusCoverageService;

/**
 * Sprint 13, build step 5 (plan.md §B.3.4) — the national-law verdict table:
 *
 * | classifyProseGap() | verdict |
 * |---|---|
 * | never_ingested | allow — `ProsePath::handle()` self-classifies and sets `$fallback = true` |
 * | expired_only   | allow — `ProsePath::handle()`'s own R15 check escalates `estatuto_fallback_gap` before retrieval either way |
 * | covered        | rewrite to `convenio_search` — its union already contains the national-law pass + convenio-precedence re-rank (Correction-03); there is no safe "national law only" retrieval mode for a covered employee |
 *
 * `employees.convenio_id` is `NOT NULL` (`create_employees_table.php:17`),
 * so "no convenio" is not reachable here.
 */
final class NationalLawPrecedenceRule implements Rule
{
    public function __construct(private readonly CorpusCoverageService $coverage) {}

    public function id(): string
    {
        return 'national_law_precedence';
    }

    public function evaluate(TurnState $state, ?array $call, ?ToolResult $result): Verdict
    {
        $convenioId = $state->employee->convenio_id;
        if ($convenioId === null) {
            return Verdict::allow();
        }

        $gap = $this->coverage->classifyProseGap((int) $convenioId);

        if ($gap === CorpusCoverageService::PROSE_COVERED) {
            return Verdict::rewrite([
                'id' => $call['id'] ?? null,
                'tool' => 'convenio_search',
                'input' => [],
            ], $this->id());
        }

        return Verdict::allow();
    }
}
