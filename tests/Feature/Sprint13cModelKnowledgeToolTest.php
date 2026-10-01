<?php

namespace Tests\Feature;

use App\Console\Commands\AnswerGate;
use App\Models\AnswerModelSetting;
use App\Models\ChatSession;
use App\Models\Convenio;
use App\Models\Employee;
use App\Models\Sector;
use App\Models\Territory;
use App\Services\Agent\RuleEngine;
use App\Services\Agent\Rules\GeneralLanePostCheck;
use App\Services\Agent\Rules\ModelKnowledgeShapeCheck;
use App\Services\Agent\ToolResult;
use App\Services\Agent\Tools\GeneralKnowledgeTool;
use App\Services\Agent\TurnState;
use App\Services\Answer\TurnOutcome;
use App\Services\Answer\TurnPersister;
use App\Services\ChatService;
use App\Services\ConversationPresenter;
use App\Services\ExtractionClient;
use App\Services\GuardrailPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Slice 13c (plan.md §2): a model-knowledge draft becomes a `basis = model_knowledge` lane answer — only under the sub-flag —
 * with no `/ground`, no citation row, its own caveat, the shape check after the post-check, and a payload/gate that
 * declare the basis. Sub-flag off = the Sprint-13 `no_web_source` NO_MATERIAL, byte for byte.
 */
class Sprint13cModelKnowledgeToolTest extends TestCase
{
    use RefreshDatabase;

    private const DRAFT = 'La excedencia es una situación en la que el contrato de trabajo queda suspendido durante un tiempo, sin que la persona trabajadora preste servicios. Sirve para atender necesidades personales o familiares. Para saber cómo se aplica en tu caso, consulta tu convenio o pregunta a Recursos Humanos.';

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $territory = Territory::create(['code' => '01', 'name' => 'Álava', 'level' => 'provincial', 'aliases' => []]);
        $sector = Sector::create(['name' => 'Sector', 'aliases' => []]);
        $convenio = Convenio::create(['numero' => '13CMK0001', 'name' => '13c model knowledge', 'territory_id' => $territory->id, 'sector_id' => $sector->id]);
        $this->employee = Employee::create([
            'email' => 'mk-13c@example.com', 'full_name' => 'Mk Trece',
            'convenio_id' => $convenio->id, 'territory_id' => $territory->id,
            'employment_type' => 'full_time', 'status' => 'active',
        ]);
        $setting = new AnswerModelSetting(['provider' => 'claude']);
        $setting->id = 1;
        $setting->save();
        $setting->setKey('sk-test-key-abcd', null);

        $this->flags(true, true);
    }

    private function flags(bool $lane, bool $modelKnowledge): void
    {
        config(['hr.general_lane.enabled' => $lane, 'hr.general_lane.model_knowledge' => $modelKnowledge]);
        GuardrailPolicy::flush();
    }

    private function state(string $question = '¿Qué es una excedencia?'): TurnState
    {
        $session = ChatSession::create(['employee_id' => $this->employee->id, 'started_at' => now(), 'last_activity_at' => now()]);

        return new TurnState($this->employee, $question, Carbon::today(), $session, []);
    }

    /** @return array<string,mixed> the /general-knowledge envelope for a model-knowledge draft */
    private function modelEnvelope(string $answer = self::DRAFT): array
    {
        return [
            'answer' => $answer,
            'sources' => [['kind' => 'model_knowledge', 'title' => 'conocimiento general']],
            'trace_fragment' => ['model' => 'claude-sonnet-5', 'general_knowledge_ms' => 900, 'prompt_tokens' => 400, 'completion_tokens' => 150, 'cost_usd' => 0.0034, 'basis' => 'model_knowledge', 'prompt_sha256' => str_repeat('a', 64), 'fetches' => []],
        ];
    }

    /** @param  array<string,mixed>  $envelope */
    private function fakeAi(array $envelope, ?bool $expectModelKnowledgeFlag = null, bool $expectGround = false): void
    {
        $this->mock(ExtractionClient::class, function ($m) use ($envelope, $expectModelKnowledgeFlag, $expectGround) {
            $call = $m->shouldReceive('generalKnowledge')->once();
            if ($expectModelKnowledgeFlag !== null) {
                $call->withArgs(fn ($q, $cat, $dom, $key, $prov, $mk = false) => $mk === $expectModelKnowledgeFlag);
            }
            $call->andReturn($envelope);
            if ($expectGround) {
                $m->shouldReceive('ground')->once()->andReturn(['grounded' => true, 'claims' => [], 'ungrounded' => []]);
            } else {
                $m->shouldReceive('ground')->never();
            }
        });
    }

    // ---- the tool ---------------------------------------------------------

    public function test_a_model_draft_becomes_a_model_basis_answer_with_no_ground_call_and_no_citation(): void
    {
        $this->fakeAi($this->modelEnvelope(), true);

        $r = $this->app->make(GeneralKnowledgeTool::class)->run([], $this->state());

        $this->assertSame(ToolResult::TERMINAL, $r->status);
        $this->assertSame('answer', $r->terminalOutcome->outcome);
        $this->assertSame([], $r->terminalOutcome->citations, 'no message_citations row is ever fabricated');
        $this->assertSame(['status' => 'answer', 'basis' => 'model_knowledge'], $r->plannerSummary);

        $t = $r->terminalOutcome->trace;
        $this->assertSame('general_knowledge', $t['floor_decision']['path']);
        $this->assertSame(['general_knowledge'], $t['floor_decision']['authority_used']);
        $lane = $t['general_lane'];
        $this->assertSame('model_knowledge', $lane['basis']);
        $this->assertSame([['kind' => 'model_knowledge', 'title' => 'conocimiento general del modelo']], $lane['sources']);
        $this->assertSame(['checked' => false, 'reason' => 'model_knowledge_no_source'], $lane['grounding']);
        $this->assertSame(['passed' => true], $lane['postcheck']);
        $this->assertSame('pass', $lane['shape']['verdict']);
        $this->assertSame(str_repeat('a', 64), $lane['prompt_sha256']);
        $this->assertSame(0.0034, $lane['draft']['cost_usd']);
        $this->assertFalse($lane['web_attempted']);
        $this->assertSame(ModelKnowledgeShapeCheck::wordCount(self::DRAFT), $lane['word_count']);
    }

    public function test_with_the_sub_flag_off_a_model_draft_is_still_discarded_as_no_web_source(): void
    {
        $this->flags(true, false);
        $this->fakeAi($this->modelEnvelope(), false);

        $r = $this->app->make(GeneralKnowledgeTool::class)->run([], $this->state());

        $this->assertSame(ToolResult::NO_MATERIAL, $r->status);
        $this->assertSame(['status' => 'no_web_source'], $r->plannerSummary);
    }

    public function test_the_request_only_carries_the_flag_when_it_is_on(): void
    {
        Http::fake(['*' => Http::response(['answer' => '', 'sources' => []])]);
        config(['services.hr_ai.url' => 'http://hr-ai.test', 'services.hr_ai.token' => 't']);
        $client = $this->app->make(ExtractionClient::class);

        $client->generalKnowledge('q', [], [], 'k', ['provider' => 'claude', 'model' => 'm', 'endpoint' => null]);
        $client->generalKnowledge('q', [], [], 'k', ['provider' => 'claude', 'model' => 'm', 'endpoint' => null], true);

        $bodies = Http::recorded()->map(fn ($pair) => $pair[0]->data())->values()->all();
        $this->assertArrayNotHasKey('model_knowledge', $bodies[0], 'flag off ⇒ the Sprint-13 request body, byte for byte');
        $this->assertTrue($bodies[1]['model_knowledge']);
    }

    public function test_a_web_source_still_takes_the_grounded_path_first(): void
    {
        $envelope = [
            'answer' => 'La excedencia es una situación de suspensión del contrato de trabajo.',
            'sources' => [['kind' => 'web', 'id' => 'sepe-x', 'title' => 'SEPE', 'excerpt' => 'texto de la página']],
            'trace_fragment' => ['fetches' => [['url' => 'https://www.sepe.es/x', 'status' => 200, 'error' => null]], 'prompt_sha256' => str_repeat('b', 64)],
        ];
        $this->fakeAi($envelope, true, expectGround: true);

        $r = $this->app->make(GeneralKnowledgeTool::class)->run([], $this->state());

        $this->assertSame('web', $r->terminalOutcome->trace['general_lane']['basis']);
        $this->assertTrue($r->terminalOutcome->trace['general_lane']['web_attempted']);
        $this->assertSame(['checked' => true, 'grounded' => true], $r->terminalOutcome->trace['general_lane']['grounding']);
    }

    public function test_a_failed_fetch_is_recorded_when_the_draft_falls_back_to_model_knowledge(): void
    {
        $env = $this->modelEnvelope();
        $env['trace_fragment']['fetches'] = [['url' => 'https://www.sepe.es/x', 'status' => 404, 'error' => 'http_404']];
        $this->fakeAi($env);

        $lane = $this->app->make(GeneralKnowledgeTool::class)->run([], $this->state())->terminalOutcome->trace['general_lane'];

        $this->assertTrue($lane['web_attempted']);
        $this->assertSame([['url' => 'https://www.sepe.es/x', 'status' => 404, 'error' => 'http_404']], $lane['fetch_errors']);
    }

    // ---- the locks, through the real rule engine ---------------------------

    /** @return array{0:string,1:?TurnOutcome} verdict kind + forced outcome */
    private function runPostCall(string $answer): array
    {
        $this->fakeAi($this->modelEnvelope($answer));
        $state = $this->state();
        $result = $this->app->make(GeneralKnowledgeTool::class)->run([], $state);
        $verdict = $this->app->make(RuleEngine::class)->run('post_call:general_knowledge', $state, ['id' => 'c1', 'tool' => 'general_knowledge', 'input' => []], $result);

        return [$verdict->rule ?? 'allow', $verdict->forcePayload, $verdict->forceType];
    }

    public function test_a_clean_draft_passes_both_locks_and_is_force_finished(): void
    {
        [$rule, $outcome, $type] = $this->runPostCall(self::DRAFT);
        $this->assertSame('general_lane_finish', $rule);
        $this->assertSame('finish', $type);
        $this->assertSame('answer', $outcome->outcome);
    }

    public function test_the_post_check_blocks_a_figure_first_and_keeps_the_lane_trace(): void
    {
        [$rule, $outcome] = $this->runPostCall('La excedencia puede durar hasta 5 años. Consulta tu convenio.');
        $this->assertSame('general_lane_post_check', $rule);
        $this->assertSame('general_lane_blocked', $outcome->escalationReason);
        $this->assertSame('model_knowledge', $outcome->trace['general_lane']['basis']);
        $this->assertFalse($outcome->trace['general_lane']['postcheck']['passed']);
    }

    public function test_the_shape_check_blocks_a_fabricated_citation_the_post_check_passes(): void
    {
        $draft = 'La excedencia está regulada en el artículo de la ley correspondiente y suspende el contrato. Para saber cómo se aplica en tu caso, consulta tu convenio.';
        $draft = str_replace('el artículo de la ley correspondiente', 'el artículo 46 del Estatuto de los Trabajadores', $draft);
        $this->assertNull(GeneralLanePostCheck::scan($draft), 'premise');

        [$rule, $outcome] = $this->runPostCall($draft);
        $this->assertSame('general_lane_shape_check', $rule);
        $this->assertSame('general_lane_blocked', $outcome->escalationReason);
        $this->assertSame(['S1'], $outcome->trace['general_lane']['shape']['rule_ids']);
    }

    public function test_the_shape_check_blocks_a_draft_with_no_closing_pointer(): void
    {
        [$rule, $outcome] = $this->runPostCall('La excedencia es una situación en la que el contrato queda suspendido durante un tiempo y la persona no presta servicios.');
        $this->assertSame('general_lane_shape_check', $rule);
        $this->assertSame(['S3'], $outcome->trace['general_lane']['shape']['rule_ids']);
    }

    // ---- persistence and rendering -------------------------------------------

    public function test_both_caveats_are_digit_free_and_clean_for_the_post_check_and_the_audit(): void
    {
        foreach ([ChatService::GENERAL_LANE_CAVEAT, ChatService::GENERAL_LANE_MODEL_CAVEAT] as $caveat) {
            $this->assertSame(0, preg_match('/\d/u', $caveat));
            $this->assertNull(GeneralLanePostCheck::scan($caveat), $caveat);
            $this->assertSame([], GeneralLanePostCheck::audit($caveat), $caveat);
        }
        $this->assertNotSame(ChatService::GENERAL_LANE_CAVEAT, ChatService::GENERAL_LANE_MODEL_CAVEAT);
    }

    public function test_decorate_picks_the_caveat_by_basis(): void
    {
        $decorate = new ReflectionMethod(TurnPersister::class, 'decorate');
        $model = $decorate->invoke(null, 'Texto.', ['floor_decision' => ['path' => 'general_knowledge'], 'general_lane' => ['basis' => 'model_knowledge']]);
        $web = $decorate->invoke(null, 'Texto.', ['floor_decision' => ['path' => 'general_knowledge'], 'general_lane' => ['basis' => 'web']]);
        $legacy = $decorate->invoke(null, 'Texto.', ['floor_decision' => ['path' => 'general_knowledge'], 'general_lane' => ['sources' => []]]);
        $other = $decorate->invoke(null, 'Texto.', ['floor_decision' => ['path' => 'prose']]);

        $this->assertSame('Texto.'.ChatService::GENERAL_LANE_MODEL_CAVEAT, $model);
        $this->assertSame('Texto.'.ChatService::GENERAL_LANE_CAVEAT, $web);
        $this->assertSame('Texto.'.ChatService::GENERAL_LANE_CAVEAT, $legacy, 'a Sprint-13 trace (no basis) keeps the web caveat');
        $this->assertSame('Texto.', $other);
    }

    public function test_the_employee_payload_carries_basis_only_on_a_lane_answer(): void
    {
        $answer = fn (string $basis) => ['floor_decision' => ['path' => 'general_knowledge', 'outcome' => 'answer'], 'general_lane' => ['basis' => $basis, 'sources' => [['kind' => 'model_knowledge', 'title' => 't']]]];

        $this->assertSame(['sources' => [], 'basis' => 'model_knowledge'], ConversationPresenter::generalLanePayload($answer('model_knowledge')));
        $this->assertSame('web', ConversationPresenter::generalLanePayload($answer('web'))['basis']);
        // blocked lane turn, and every other path: exactly the pre-13c shape
        $this->assertSame(['sources' => []], ConversationPresenter::generalLanePayload(['floor_decision' => ['path' => 'general_knowledge', 'outcome' => 'escalate'], 'general_lane' => ['basis' => 'model_knowledge']]));
        $this->assertSame(['sources' => []], ConversationPresenter::generalLanePayload(['floor_decision' => ['path' => 'prose', 'outcome' => 'answer']]));
        $this->assertSame(['sources' => []], ConversationPresenter::generalLanePayload([]));
    }

    // ---- the answer:gate invariant --------------------------------------------

    /**
     * @param  array<string,mixed>  $lane
     * @return array<string,mixed>
     */
    private function score(string $answer, array $lane, array $citations = []): array
    {
        $m = new ReflectionMethod(AnswerGate::class, 'scoreCase');
        $trace = ['floor_decision' => ['path' => 'general_knowledge', 'outcome' => 'answer', 'authority_used' => ['general_knowledge']], 'general_lane' => $lane];
        $result = ['outcome' => 'answer', 'answer' => $answer, 'citations' => $citations];

        return $m->invoke($this->app->make(AnswerGate::class), ['id' => 'x', 'expect' => ['forbid_paths' => []]], 'agent', $result, $trace, null);
    }

    public function test_the_gate_accepts_a_declared_basis_and_rejects_the_undeclared_ones(): void
    {
        $modelOk = ['basis' => 'model_knowledge', 'sources' => [['kind' => 'model_knowledge']], 'postcheck' => ['passed' => true], 'shape' => ['verdict' => 'pass'], 'word_count' => 50];
        $row = $this->score(self::DRAFT.ChatService::GENERAL_LANE_MODEL_CAVEAT, $modelOk);
        $this->assertFalse($row['must_not_answer_violated']);
        $this->assertSame('model_knowledge', $row['lane_basis']);
        $this->assertSame(50, $row['lane_words']);

        $web = ['basis' => 'web', 'sources' => [['kind' => 'web', 'id' => 'x']], 'word_count' => 40];
        $this->assertFalse($this->score(self::DRAFT.ChatService::GENERAL_LANE_CAVEAT, $web)['must_not_answer_violated']);

        // the old invariant's case (Sprint 13 trace: no basis, no web source) still fails, now under the new name
        $this->assertSame('lane_answer_without_declared_basis', $this->score(self::DRAFT.ChatService::GENERAL_LANE_MODEL_CAVEAT, ['sources' => [['kind' => 'model_knowledge']]])['hard_kind']);
        // model basis without a recorded clean shape / post-check
        $this->assertSame('lane_answer_without_declared_basis', $this->score(self::DRAFT.ChatService::GENERAL_LANE_MODEL_CAVEAT, ['shape' => ['verdict' => 'blocked']] + $modelOk)['hard_kind']);
        $this->assertSame('lane_answer_without_declared_basis', $this->score(self::DRAFT.ChatService::GENERAL_LANE_MODEL_CAVEAT, ['postcheck' => ['passed' => false]] + $modelOk)['hard_kind']);
        // model basis with a citation row
        $this->assertSame('lane_model_answer_with_citation', $this->score(self::DRAFT.ChatService::GENERAL_LANE_MODEL_CAVEAT, $modelOk, [['document_id' => 1]])['hard_kind']);
        // a digit, and length, stay hard
        $this->assertSame('lane_answer_has_digit', $this->score('Dura 5 años.'.ChatService::GENERAL_LANE_MODEL_CAVEAT, $modelOk)['hard_kind']);
        $this->assertSame('lane_answer_too_long', $this->score(self::DRAFT.ChatService::GENERAL_LANE_MODEL_CAVEAT, ['word_count' => 121] + $modelOk)['hard_kind']);
    }

    public function test_a_lane_answer_without_its_basis_specific_caveat_is_a_hard_failure(): void
    {
        $modelOk = ['basis' => 'model_knowledge', 'sources' => [['kind' => 'model_knowledge']], 'postcheck' => ['passed' => true], 'shape' => ['verdict' => 'pass'], 'word_count' => 50];
        $web = ['basis' => 'web', 'sources' => [['kind' => 'web', 'id' => 'x']], 'word_count' => 40];

        $this->assertSame('lane_answer_without_caveat', $this->score(self::DRAFT, $modelOk)['hard_kind']);
        $this->assertSame('lane_answer_without_caveat', $this->score(self::DRAFT, $web)['hard_kind']);
        // the WRONG basis' caveat is not enough
        $this->assertSame('lane_answer_without_caveat', $this->score(self::DRAFT.ChatService::GENERAL_LANE_CAVEAT, $modelOk)['hard_kind']);
        $this->assertSame('lane_answer_without_caveat', $this->score(self::DRAFT.ChatService::GENERAL_LANE_MODEL_CAVEAT, $web)['hard_kind']);
        // the row carries the full text
        $this->assertSame(self::DRAFT.ChatService::GENERAL_LANE_MODEL_CAVEAT, $this->score(self::DRAFT.ChatService::GENERAL_LANE_MODEL_CAVEAT, $modelOk)['answer']);
    }
}
