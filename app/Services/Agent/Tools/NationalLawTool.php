<?php

namespace App\Services\Agent\Tools;

use App\Models\AnswerModelSetting;
use App\Services\Agent\Normalization\ConsumerTrace;
use App\Services\Agent\Rules\CorpusMiss;
use App\Services\Agent\Rules\NationalLawPrecedenceRule;
use App\Services\Agent\Tool;
use App\Services\Agent\ToolResult;
use App\Services\Agent\TurnState;
use App\Services\Answer\ProsePath;
use App\Services\GuardrailPolicy;

/**
 * Sprint 13, build step 5 (plan.md §B.3.4) — "callable directly" national-law
 * lookup, the plan's own spec/code tension: `ProsePath::handle()` has no
 * caller-settable "national law ONLY" retrieval mode — `$fallback` is
 * computed internally from `CorpusCoverageService::classifyProseGap()`
 * (true only when `never_ingested`). So this tool calls the EXACT SAME
 * `ProsePath::handle()` `convenio_search` calls; the only genuinely distinct
 * behaviour lives in {@see NationalLawPrecedenceRule}
 * (`pre_call:national_law`), which rewrites to `convenio_search` outright on
 * a `covered` convenio (that tool's own union already contains the
 * national-law pass and the convenio-precedence re-rank — there is no safe
 * way to search "national law only" for a covered employee without
 * bypassing that precedence, ADR/Correction-03). On `never_ingested` this
 * tool's call is behaviourally identical to `convenio_search`'s (both let
 * `ProsePath::handle()` self-classify and set `$fallback = true`); on
 * `expired_only`, `ProsePath::handle()`'s own R15 check escalates
 * `estatuto_fallback_gap` before retrieval either way — no separate
 * pre-call force needed, same reasoning as `ConvenioSearchTool`'s docblock.
 *
 * `input_schema: {}` — no planner-supplied retrieval rephrasings for this
 * tool (unlike `convenio_search`); it is meant as a plain "check the
 * national minimum" call, not a compound-question retrieval aid.
 */
final class NationalLawTool implements Tool
{
    public function __construct(
        private readonly ProsePath $prosePath,
        private readonly GuardrailPolicy $guardrails,
    ) {}

    public function name(): string
    {
        return 'national_law';
    }

    public function definition(): array
    {
        return [
            'name' => 'national_law',
            'description' => 'Consulta el mínimo legal del Estatuto de los Trabajadores para el tema de la '
                .'pregunta, cuando no exista texto de convenio cargado para la persona. Si su convenio está '
                .'cargado, el sistema la sustituye por convenio_search.',
            'input_schema' => ['type' => 'object', 'properties' => [], 'additionalProperties' => false],
        ];
    }

    public function countsAsClarification(): bool
    {
        return false;
    }

    public function run(array $input, TurnState $state): ToolResult
    {
        $settings = AnswerModelSetting::current();
        $decryptedKey = $settings->isConfigured() ? $settings->decryptKey() : null;

        // Sprint 13b (plan.md §4.2): a validated canonical is unioned into retrieval; the literal question
        // stays the question (synthesis, grounding, the national-law pass) and its own top hits are protected.
        $canonical = $state->normalizedCanonical();
        $outcome = $this->prosePath->handle($state->employee, $state->question, [], $state->asOfDate, $decryptedKey, $state->trace, $canonical !== null ? [$canonical] : [], $canonical !== null);

        if ($canonical !== null) {
            $state->recordNormalizationConsumer(ConsumerTrace::retrieval('national_law', $outcome, $canonical, $this->guardrails->retrievalFloor()));
        }

        if ($outcome->outcome === 'answer') {
            return new ToolResult(ToolResult::TERMINAL, terminalOutcome: $outcome, plannerSummary: ['status' => 'answer']);
        }

        $checkA = $outcome->trace['floor_decision']['check_a_retrieval'] ?? true;
        if ($checkA === false) {
            return new ToolResult(
                ToolResult::NO_MATERIAL,
                terminalOutcome: $outcome,
                traceBlocks: ['floor_decision' => $outcome->trace['floor_decision'], 'retrieval' => $outcome->trace['retrieval'] ?? []],
                plannerSummary: ['status' => 'check_a_failed', 'gap_class' => $outcome->trace['prose_gap']['classification'] ?? null],
            );
        }

        // CP-1 amendment (F.8, plan §B.6.1 cond. 2) — same rule as ConvenioSearchTool.
        // Slice 13c: with the model-knowledge sub-flag on, a synthesis ABSTENTION (status `abstained`) is handed over the same way.
        $handOver = CorpusMiss::handOverKind($outcome, $state->question, $this->guardrails);
        if ($handOver !== null) {
            return new ToolResult(
                ToolResult::NO_MATERIAL,
                terminalOutcome: $outcome,
                traceBlocks: ['floor_decision' => $outcome->trace['floor_decision'], 'retrieval' => $outcome->trace['retrieval'] ?? []] + CorpusMiss::precondition($outcome),
                plannerSummary: CorpusMiss::plannerSummary($handOver, $outcome),
            );
        }

        return new ToolResult(ToolResult::TERMINAL, terminalOutcome: $outcome, plannerSummary: [
            'status' => 'escalate',
            'escalation_reason' => $outcome->escalationReason,
        ]);
    }
}
