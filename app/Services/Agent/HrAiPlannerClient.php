<?php

namespace App\Services\Agent;

use App\Models\AnswerModelSetting;
use App\Services\ExtractionClient;

/**
 * Sprint 13, build step 6 (plan.md §C.7–C.10) — the real `PlannerClient`.
 * Decrypts the shared answer-model key (same path as `/route`/`/synthesise`,
 * ADR-0015), calls hr-ai `/plan`, and schema-validates the envelope before
 * the loop ever sees it. A missing key, a transport error, or an unparseable
 * body becomes `PlannerUnavailableException` so `AgentChatService::handle()`
 * falls back to classic for that turn (§F.10).
 */
final class HrAiPlannerClient implements PlannerClient
{
    public function __construct(private readonly ExtractionClient $ai) {}

    public function plan(string $question, array $scopeSummary, array $window, array $toolDefinitions, array $priorSteps): array
    {
        $settings = AnswerModelSetting::current();
        if (! $settings->isConfigured()) {
            throw new PlannerUnavailableException('answer model key is not configured — cannot call /plan');
        }

        $enabled = [];
        foreach ($toolDefinitions as $def) {
            if (is_string($def['name'] ?? null)) {
                $enabled[] = $def['name'];
            }
        }
        if ($enabled === []) {
            throw new PlannerUnavailableException('no enabled tools were passed to /plan');
        }

        $decryptedKey = $settings->decryptKey();
        $raw = $this->ai->plan(
            $question,
            $scopeSummary,
            $window,
            $enabled,
            $priorSteps,
            $decryptedKey,
            [
                'provider' => config('services.hr_ai.answer_provider', 'claude'),
                'model' => config('services.hr_ai.planner_model'),
                'endpoint' => config('services.hr_ai.planner_endpoint'),
            ],
        );
        unset($decryptedKey);

        if (isset($raw['error'])) {
            throw new PlannerUnavailableException('hr-ai /plan: '.((string) ($raw['detail'] ?? $raw['error'])));
        }

        return self::validate($raw, $enabled);
    }

    /**
     * Backend-side schema validation of planner output (§E.15 step 6).
     * Unknown tools are dropped (mirrors hr-ai's own normalizer — defence in
     * depth if a future transport skip that filter). An envelope that is not
     * an object, or whose `calls` is not a list, is unparseable.
     *
     * @param  array<string,mixed>  $raw
     * @param  list<string>  $enabled
     * @return array{stop_reason:string,calls:list<array{id:string,tool:string,input:array<string,mixed>}>,model:?string,request_id:?string,prompt_version:?string,tokens:array<string,mixed>,ms:int}
     */
    public static function validate(array $raw, array $enabled): array
    {
        $allowed = array_fill_keys($enabled, true);
        $calls = $raw['calls'] ?? null;
        if (! is_array($calls)) {
            throw new PlannerUnavailableException('hr-ai /plan response missing a calls list');
        }

        $normalized = [];
        foreach (array_values($calls) as $i => $call) {
            if (! is_array($call)) {
                continue;
            }
            $tool = $call['tool'] ?? $call['name'] ?? null;
            if (! is_string($tool) || ! isset($allowed[$tool])) {
                continue;
            }
            $input = $call['input'] ?? [];
            if (! is_array($input)) {
                continue;
            }
            $id = $call['id'] ?? null;
            if (! is_string($id) || $id === '') {
                $id = 'call_'.($i + 1);
            }
            $normalized[] = ['id' => $id, 'tool' => $tool, 'input' => $input];
        }

        $tokens = is_array($raw['tokens'] ?? null) ? $raw['tokens'] : [];

        return [
            'stop_reason' => is_string($raw['stop_reason'] ?? null) ? $raw['stop_reason'] : 'tool_use',
            'calls' => $normalized,
            'model' => is_string($raw['model'] ?? null) ? $raw['model'] : null,
            'request_id' => is_string($raw['request_id'] ?? null) ? $raw['request_id'] : null,
            'prompt_version' => is_string($raw['prompt_version'] ?? null) ? $raw['prompt_version'] : null,
            'tokens' => $tokens,
            'ms' => is_int($raw['ms'] ?? null) ? $raw['ms'] : (int) ($raw['ms'] ?? 0),
        ];
    }
}
