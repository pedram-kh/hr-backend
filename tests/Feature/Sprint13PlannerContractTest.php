<?php

namespace Tests\Feature;

use App\Models\AnswerModelSetting;
use App\Services\Agent\HrAiPlannerClient;
use App\Services\Agent\PlannerUnavailableException;
use App\Services\ExtractionClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sprint 13, build step 6 (plan.md §E.15) — backend schema validation of
 * planner output, plus the real client's fail-closed path when the key is
 * missing or hr-ai returns `{error: ...}`.
 */
class Sprint13PlannerContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_validate_drops_unknown_tools_and_malformed_calls(): void
    {
        $raw = [
            'stop_reason' => 'tool_use',
            'calls' => [
                ['id' => 't1', 'tool' => 'convenio_search', 'input' => ['query' => 'vacaciones']],
                ['id' => 't2', 'tool' => 'invented_tool', 'input' => []],
                ['tool' => 'finalize', 'input' => 'not-an-object'],
                ['id' => 't3', 'name' => 'finalize', 'input' => ['use' => ['t1']]],
            ],
            'model' => 'claude-sonnet-5',
            'request_id' => 'req_1',
            'prompt_version' => 'sha256:abc',
            'tokens' => ['prompt' => 10],
            'ms' => 12,
        ];

        $out = HrAiPlannerClient::validate($raw, ['convenio_search', 'finalize']);

        $this->assertSame(['convenio_search', 'finalize'], array_column($out['calls'], 'tool'));
        $this->assertSame('t1', $out['calls'][0]['id']);
        $this->assertSame(['use' => ['t1']], $out['calls'][1]['input']);
        $this->assertSame('sha256:abc', $out['prompt_version']);
    }

    public function test_validate_rejects_a_missing_calls_list(): void
    {
        $this->expectException(PlannerUnavailableException::class);
        HrAiPlannerClient::validate(['stop_reason' => 'tool_use'], ['finalize']);
    }

    public function test_real_client_throws_when_the_answer_key_is_not_configured(): void
    {
        AnswerModelSetting::query()->delete();

        $this->expectException(PlannerUnavailableException::class);
        $this->expectExceptionMessage('answer model key is not configured');

        app(HrAiPlannerClient::class)->plan('hola', [], ['message_ids' => []], [
            ['name' => 'finalize', 'description' => '', 'input_schema' => []],
        ], []);
    }

    public function test_real_client_throws_when_hr_ai_returns_an_error_envelope(): void
    {
        $s = new AnswerModelSetting(['provider' => 'claude']);
        $s->id = 1;
        $s->setKey('test-key-1234');

        $fake = new class extends ExtractionClient
        {
            public function plan(string $question, array $scopeSummary, array $window, array $enabledTools, array $priorSteps, string $decryptedKey, array $providerConfig): array
            {
                return ['error' => 'provider_error', 'detail' => 'boom'];
            }
        };
        $this->app->instance(ExtractionClient::class, $fake);

        $this->expectException(PlannerUnavailableException::class);
        $this->expectExceptionMessage('boom');

        app(HrAiPlannerClient::class)->plan('hola', [], ['message_ids' => []], [
            ['name' => 'finalize', 'description' => '', 'input_schema' => []],
        ], []);
    }

    public function test_real_client_accepts_a_well_shaped_envelope_and_forwards_enabled_tool_names(): void
    {
        $s = new AnswerModelSetting(['provider' => 'claude']);
        $s->id = 1;
        $s->setKey('test-key-1234');

        $seen = (object) ['enabled' => null];
        $fake = new class($seen) extends ExtractionClient
        {
            public function __construct(private object $seen) {}

            public function plan(string $question, array $scopeSummary, array $window, array $enabledTools, array $priorSteps, string $decryptedKey, array $providerConfig): array
            {
                $this->seen->enabled = $enabledTools;

                return [
                    'stop_reason' => 'tool_use',
                    'calls' => [['id' => 't1', 'tool' => 'escalate', 'input' => ['category' => 'other', 'reason' => 'x']]],
                    'model' => $providerConfig['model'],
                    'request_id' => 'r1',
                    'prompt_version' => 'sha256:x',
                    'tokens' => [],
                    'ms' => 3,
                ];
            }
        };
        $this->app->instance(ExtractionClient::class, $fake);

        $out = app(HrAiPlannerClient::class)->plan('hola', ['convenio_name' => 'X'], ['message_ids' => []], [
            ['name' => 'escalate', 'description' => '', 'input_schema' => []],
            ['name' => 'finalize', 'description' => '', 'input_schema' => []],
        ], []);

        $this->assertSame(['escalate', 'finalize'], $seen->enabled);
        $this->assertSame('escalate', $out['calls'][0]['tool']);
        $this->assertSame(config('services.hr_ai.planner_model'), $out['model']);
    }
}
