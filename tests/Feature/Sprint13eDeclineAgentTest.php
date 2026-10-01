<?php

namespace Tests\Feature;

use App\Jobs\GenerateEscalationExplanationText;
use App\Models\AnswerModelSetting;
use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Models\Convenio;
use App\Models\Employee;
use App\Models\EscalationCard;
use App\Models\GuardrailBlockedTopic;
use App\Models\GuardrailConfig;
use App\Models\MessageTrace;
use App\Models\Sector;
use App\Models\Territory;
use App\Services\Agent\AgentChatService;
use App\Services\Agent\PlannerClient;
use App\Services\ChatService;
use App\Services\ExtractionClient;
use App\Services\GuardrailPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Slice 13e (ADR-0039, plan.md §4.3/§8.4) — the planner path of the decline outcome, with a scripted planner and a scripted
 * router: a planner `off_domain` escalation becomes a decline only when the gate grants it; every other planner escalation,
 * and every denial, is today's `planner_escalated` card, byte for byte.
 */
class Sprint13eDeclineAgentTest extends TestCase
{
    use RefreshDatabase;

    private Territory $territory;

    private Sector $sector;

    /** @var list<string> the questions the fake router was asked */
    private array $routed = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->territory = Territory::create(['code' => '31', 'name' => 'Navarra', 'level' => 'provincial', 'aliases' => []]);
        $this->sector = Sector::create(['name' => 'Hostelería', 'aliases' => []]);
        AnswerModelSetting::query()->delete();
        $s = new AnswerModelSetting(['provider' => 'claude']);
        $s->id = 1;
        $s->setKey('test-key-1234');
        GuardrailPolicy::flush();
        Queue::fake();
    }

    private function employee(): Employee
    {
        $convenio = Convenio::create([
            'numero' => '13E-AG', 'name' => 'Convenio 13e', 'territory_id' => $this->territory->id, 'sector_id' => $this->sector->id,
        ]);

        return Employee::create([
            'email' => 'dec-agent@example.com', 'full_name' => 'Decline Agent Test',
            'convenio_id' => $convenio->id, 'territory_id' => $this->territory->id,
            'employment_type' => 'full_time', 'status' => 'active',
        ]);
    }

    private function chatSession(Employee $employee, ?string $previousOutcome = null): ChatSession
    {
        $session = ChatSession::create(['employee_id' => $employee->id, 'started_at' => now(), 'last_activity_at' => now()]);
        ChatMessage::create(['session_id' => $session->id, 'role' => 'user', 'content' => 'Hola, tengo una duda.']);
        $a = ChatMessage::create(['session_id' => $session->id, 'role' => 'assistant', 'content' => 'Claro, dime.']);
        if ($previousOutcome !== null) {
            MessageTrace::create(['message_id' => $a->id, 'trace' => ['floor_decision' => ['outcome' => $previousOutcome]]]);
        }

        return $session;
    }

    /** @param  array<string,mixed>  $input */
    private function bindPlanner(string $category, string $reason = 'no es de trabajo'): void
    {
        $planner = new class($category, $reason) implements PlannerClient
        {
            public function __construct(private string $category, private string $reason) {}

            public function plan(string $question, array $scopeSummary, array $window, array $toolDefinitions, array $priorSteps): array
            {
                return ['calls' => [['id' => 't1', 'tool' => 'escalate', 'input' => ['category' => $this->category, 'reason' => $this->reason]]], 'stop_reason' => 'tool_use', 'model' => null, 'request_id' => null, 'prompt_version' => null, 'tokens' => [], 'ms' => 0];
            }
        };
        $this->app->bind(PlannerClient::class, fn () => $planner);
    }

    /** @param  ?array<string,mixed>  $route  the router's answer; null = any /route call fails the test */
    private function bindRouter(?array $route): void
    {
        $self = $this;
        $fake = new class($route, $self) extends ExtractionClient
        {
            public function __construct(private ?array $route, private $test) {}

            public function route(string $question, string $decryptedKey, array $providerConfig): array
            {
                if ($this->route === null) {
                    throw new \RuntimeException('the router must not be consulted for this turn');
                }
                $this->test->recordRoute($question);

                return $this->route;
            }
        };
        $this->app->instance(ExtractionClient::class, $fake);
    }

    public function recordRoute(string $q): void
    {
        $this->routed[] = $q;
    }

    private function offDomainRoute(float $confidence = 0.95, string $label = 'off_domain'): array
    {
        return ['label' => $label, 'confidence' => $confidence, 'subqueries' => [], 'reason' => null, 'trace_fragment' => []];
    }

    private function ask(Employee $e, ChatSession $s, string $q): array
    {
        return app(AgentChatService::class)->handle($e, $q, $s->uuid);
    }

    public function test_a_confirmed_planner_off_domain_is_declined_with_no_card_and_no_explanation_job(): void
    {
        $e = $this->employee();
        $this->bindPlanner('off_domain');
        $this->bindRouter($this->offDomainRoute());

        $r = $this->ask($e, $this->chatSession($e), '¿Cuál es la capital de Australia?');

        $this->assertSame('decline', $r['outcome']);
        $this->assertFalse($r['escalated']);
        $this->assertNull($r['escalation_reason']);
        $this->assertNull($r['escalation_uuid']);
        $this->assertSame(ChatService::DECLINE_MESSAGE, $r['answer']);
        $this->assertSame([], $r['citations']);
        $this->assertSame(0, EscalationCard::count(), 'a decline writes no card');
        Queue::assertNotPushed(GenerateEscalationExplanationText::class);

        $t = $r['trace'];
        $this->assertSame('decline', $t['floor_decision']['outcome']);
        $this->assertSame('off_domain', $t['floor_decision']['decline_reason']);
        $this->assertArrayNotHasKey('escalation_reason', $t['floor_decision']);
        $this->assertTrue($t['decline']['granted']);
        $this->assertSame('planner', $t['decline']['source']);
        $this->assertSame('off_domain', $t['decline']['confirm']['label']);
        $this->assertSame(['category' => 'off_domain', 'reason' => 'no es de trabajo'], $t['agent']['planner_escalation']);
        $this->assertSame('declined', $t['agent']['termination']);
        $verdicts = array_values(array_filter($t['agent']['steps'], fn ($s) => $s['type'] === 'rule_verdict'));
        $this->assertSame('off_domain_decline', $verdicts[0]['rule']);
        $this->assertSame('force_decline', $verdicts[0]['verdict']);
        $this->assertSame(1, count($this->routed), 'exactly one router confirmation is spent');

        // The persisted turn is a decline too (what Historial and the hydrated chat read).
        $stored = MessageTrace::where('message_id', $r['message_id'])->first()->trace;
        $this->assertSame('decline', $stored['floor_decision']['outcome']);
    }

    public function test_the_admin_off_domain_text_is_the_decline_text_when_configured(): void
    {
        $e = $this->employee();
        GuardrailConfig::current()->forceFill(['off_domain_message' => 'Solo RR. HH.'])->save();
        GuardrailPolicy::flush();
        $this->bindPlanner('off_domain');
        $this->bindRouter($this->offDomainRoute());

        $r = $this->ask($e, $this->chatSession($e), '¿Cuál es la capital de Australia?');

        $this->assertSame('decline', $r['outcome']);
        $this->assertSame('Solo RR. HH.', $r['answer']);
    }

    /** @return array<string,array{0:array<string,mixed>,1:string}> */
    public static function routerDenials(): array
    {
        return [
            'router says prose' => [['label' => 'prose', 'confidence' => 0.95, 'subqueries' => [], 'reason' => null, 'trace_fragment' => []], 'D8'],
            'router off_domain at 0.89' => [['label' => 'off_domain', 'confidence' => 0.89, 'subqueries' => [], 'reason' => null, 'trace_fragment' => []], 'D8'],
            'router below its own floor falls back to prose' => [['label' => 'off_domain', 'confidence' => 0.30, 'subqueries' => [], 'reason' => null, 'trace_fragment' => []], 'D8'],
            'router says salary' => [['label' => 'salary', 'confidence' => 1.0, 'subqueries' => [], 'reason' => null, 'trace_fragment' => []], 'D8'],
        ];
    }

    /** @param  array<string,mixed>  $route */
    #[DataProvider('routerDenials')]
    public function test_without_router_agreement_the_planners_off_domain_stays_an_escalation(array $route, string $deniedBy): void
    {
        $e = $this->employee();
        $this->bindPlanner('off_domain');
        $this->bindRouter($route);

        $r = $this->ask($e, $this->chatSession($e), '¿Cuál es la capital de Australia?');

        $this->assertSame('escalate', $r['outcome']);
        $this->assertSame('planner_escalated', $r['escalation_reason']);
        $this->assertSame(ChatService::EMPLOYEE_ESCALATION_MESSAGE, $r['answer']);
        $this->assertNotNull($r['escalation_uuid']);
        $this->assertFalse($r['trace']['decline']['granted']);
        $this->assertSame($deniedBy, $r['trace']['decline']['denied_by']);
        $this->assertSame(1, EscalationCard::count());
    }

    public function test_a_workplace_word_vetoes_the_decline_without_spending_the_router_call(): void
    {
        $e = $this->employee();
        $this->bindPlanner('off_domain');
        $this->bindRouter(null); // any /route call throws

        $r = $this->ask($e, $this->chatSession($e), '¿Puedo llevar a mi perro a la oficina?');

        $this->assertSame('escalate', $r['outcome']);
        $this->assertSame('planner_escalated', $r['escalation_reason']);
        $this->assertSame('D7', $r['trace']['decline']['denied_by']);
        $this->assertSame('oficina', collect($r['trace']['decline']['checks'])->firstWhere('id', 'D7')['detail']);
        $this->assertSame(1, EscalationCard::count());
    }

    public function test_the_reply_to_our_own_clarifying_question_is_never_declined(): void
    {
        $e = $this->employee();
        $this->bindPlanner('off_domain');
        $this->bindRouter(null);

        foreach (['ask', 'needs_category'] as $previous) {
            $r = $this->ask($e, $this->chatSession($e, $previous), 'el 15 de marzo');
            $this->assertSame('escalate', $r['outcome'], "previous turn {$previous}");
            $this->assertSame('D6', $r['trace']['decline']['denied_by']);
        }
    }

    /** @return array<string,array{0:string}> */
    public static function otherCategories(): array
    {
        return ['unsafe' => ['unsafe'], 'unanswerable' => ['unanswerable'], 'needs_human_judgement' => ['needs_human_judgement'], 'other' => ['other']];
    }

    #[DataProvider('otherCategories')]
    public function test_no_other_planner_category_is_ever_declined_nor_even_evaluated(string $category): void
    {
        $e = $this->employee();
        $this->bindPlanner($category);
        $this->bindRouter(null);

        $r = $this->ask($e, $this->chatSession($e), '¿Cuál es la capital de Australia?');

        $this->assertSame('escalate', $r['outcome']);
        $this->assertSame('planner_escalated', $r['escalation_reason']);
        $this->assertArrayNotHasKey('decline', $r['trace'], 'the gate is only evaluated for an off_domain verdict');
        $this->assertSame(1, EscalationCard::count());
    }

    public function test_the_kill_switch_restores_the_escalation(): void
    {
        config(['hr.decline.enabled' => false]);
        $e = $this->employee();
        $this->bindPlanner('off_domain');
        $this->bindRouter(null);

        $r = $this->ask($e, $this->chatSession($e), '¿Cuál es la capital de Australia?');

        $this->assertSame('escalate', $r['outcome']);
        $this->assertSame('planner_escalated', $r['escalation_reason']);
        $this->assertArrayNotHasKey('decline', $r['trace'], 'with the switch off the trace is exactly the pre-13e one');
        $this->assertSame(1, EscalationCard::count());
    }

    public function test_an_admin_blocked_topic_row_vetoes_a_planner_decline(): void
    {
        $e = $this->employee();
        GuardrailBlockedTopic::create(['pattern' => 'capital', 'kind' => GuardrailBlockedTopic::KIND_BLOCKED_TOPIC, 'enabled' => true]);
        GuardrailPolicy::flush();
        $this->bindPlanner('off_domain');
        $this->bindRouter(null);

        // The pre-model guard escalates a blocked topic before the planner is even called; either way it is not a decline.
        $r = $this->ask($e, $this->chatSession($e), '¿Cuál es la capital de Australia?');

        $this->assertSame('escalate', $r['outcome']);
        $this->assertSame('sensitive_topic', $r['escalation_reason']);
    }
}
