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
use App\Models\MessageTrace;
use App\Models\Sector;
use App\Models\Territory;
use App\Services\Answer\TurnOutcome;
use App\Services\Answer\TurnPersister;
use App\Services\ChatService;
use App\Services\Decline\DeclineFacts;
use App\Services\Decline\DeclineGate;
use App\Services\Decline\DeclineGrant;
use App\Services\ExtractionClient;
use App\Services\GuardrailPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Slice 13e (ADR-0039, plan.md §4.3/§4.4/§6) — the GUARD source (the admin's own off-domain list, shared by classic and
 * agent through PreModelGuards), the classic router path that must NOT change (Q2), and the persister's fail-closed re-check.
 */
class Sprint13eDeclineGuardTest extends TestCase
{
    use RefreshDatabase;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $territory = Territory::create(['code' => '28', 'name' => 'Madrid', 'level' => 'provincial', 'aliases' => []]);
        $sector = Sector::create(['name' => 'Sector', 'aliases' => []]);
        $convenio = Convenio::create(['numero' => '13E-GD', 'name' => 'Convenio 13e', 'territory_id' => $territory->id, 'sector_id' => $sector->id]);
        $this->employee = Employee::create([
            'email' => 'dec-guard@example.com', 'full_name' => 'Decline Guard Test',
            'convenio_id' => $convenio->id, 'territory_id' => $territory->id, 'employment_type' => 'full_time', 'status' => 'active',
        ]);
        Queue::fake();
        GuardrailPolicy::flush();
    }

    private function row(string $pattern, string $kind = GuardrailBlockedTopic::KIND_OFF_DOMAIN): void
    {
        GuardrailBlockedTopic::create(['pattern' => $pattern, 'kind' => $kind, 'enabled' => true]);
        GuardrailPolicy::flush();
    }

    /** @return array<string,mixed> */
    private function classic(string $q, ?string $sessionUuid = null): array
    {
        // No AI is bound: the guard path must settle every turn here without a single hr-ai call.
        return app(ChatService::class)->handleMessage($this->employee, $q, $sessionUuid);
    }

    public function test_the_admins_off_domain_pattern_declines_without_a_card_or_an_ai_call(): void
    {
        $this->row('gimnasio');

        $r = $this->classic('¿La empresa paga el gimnasio?');

        $this->assertSame('decline', $r['outcome']);
        $this->assertSame(ChatService::DECLINE_MESSAGE, $r['answer']);
        $this->assertNull($r['escalation_uuid']);
        $this->assertSame(0, EscalationCard::count());
        Queue::assertNotPushed(GenerateEscalationExplanationText::class);
        $this->assertSame('guard_admin', $r['trace']['decline']['source']);
        $this->assertSame('gimnasio', $r['trace']['decline']['matched_pattern']);
        $this->assertSame('pre_model_guard', $r['trace']['floor_decision']['path']);
    }

    public function test_an_off_domain_row_cannot_shadow_a_later_blocked_topic_row(): void
    {
        $this->row('gimnasio');                                            // off_domain, first
        $this->row('empresa', GuardrailBlockedTopic::KIND_BLOCKED_TOPIC); // sensitive, second — blockedTopicMatch() stops at the first

        $r = $this->classic('¿La empresa paga el gimnasio?');

        $this->assertSame('escalate', $r['outcome']);
        $this->assertSame('off_domain', $r['escalation_reason'], 'today\'s behaviour is kept on a denial');
        $this->assertSame('D2', $r['trace']['decline']['denied_by']);
        $this->assertSame(1, EscalationCard::count());
    }

    public function test_an_explicit_request_is_never_declined_even_with_a_matching_pattern(): void
    {
        $this->row('gimnasio');

        $r = $this->classic('Quiero hablar con una persona de RR. HH., es sobre el gimnasio');

        $this->assertSame('escalate', $r['outcome']);
        $this->assertSame('D3', $r['trace']['decline']['denied_by']);
        $this->assertSame(1, EscalationCard::count());
    }

    public function test_the_reply_to_our_own_clarifying_question_is_not_declined(): void
    {
        $this->row('gimnasio');
        $session = ChatSession::create(['employee_id' => $this->employee->id, 'started_at' => now(), 'last_activity_at' => now()]);
        ChatMessage::create(['session_id' => $session->id, 'role' => 'user', 'content' => 'duda']);
        $a = ChatMessage::create(['session_id' => $session->id, 'role' => 'assistant', 'content' => '¿De qué trata?']);
        MessageTrace::create(['message_id' => $a->id, 'trace' => ['floor_decision' => ['outcome' => 'ask']]]);

        $r = $this->classic('el gimnasio', $session->uuid);

        $this->assertSame('escalate', $r['outcome']);
        $this->assertSame('D6', $r['trace']['decline']['denied_by']);
    }

    /** @return array<string,array{0:string,1:string}> */
    public static function baselineQuestions(): array
    {
        return [
            'legal advice' => ['necesito consejo legal sobre esto, ¿debería contratar un abogado?', 'off_domain'],
            'other employee data' => ['¿cuánto gana Pedro García en la empresa?', 'off_domain'],
            'harassment' => ['tengo un problema de acoso laboral con mi jefe', 'sensitive_topic'],
        ];
    }

    #[DataProvider('baselineQuestions')]
    public function test_the_baseline_guards_are_never_declined(string $q, string $reason): void
    {
        $this->row('gimnasio');

        $r = $this->classic($q);

        $this->assertSame('escalate', $r['outcome']);
        $this->assertSame($reason, $r['escalation_reason']);
        $this->assertArrayNotHasKey('decline', $r['trace'], 'the baseline path never evaluates the gate');
        $this->assertSame(1, EscalationCard::count());
    }

    public function test_the_classic_router_off_domain_stays_an_escalation(): void
    {
        // Q2: it needs its own confirmation signal and probe — a ticket, not this slice.
        AnswerModelSetting::query()->delete();
        $s = new AnswerModelSetting(['provider' => 'claude']);
        $s->id = 1;
        $s->setKey('test-key-1234');
        $this->app->instance(ExtractionClient::class, new class extends ExtractionClient
        {
            public function __construct() {}

            public function route(string $question, string $decryptedKey, array $providerConfig): array
            {
                return ['label' => 'off_domain', 'confidence' => 0.99, 'subqueries' => [], 'reason' => null, 'trace_fragment' => []];
            }
        });

        $r = $this->classic('¿Cuál es la capital de Francia?');

        $this->assertSame('escalate', $r['outcome']);
        $this->assertSame('off_domain', $r['escalation_reason']);
        $this->assertArrayNotHasKey('decline', $r['trace']);
    }

    public function test_the_kill_switch_leaves_no_decline_key_on_the_trace(): void
    {
        config(['hr.decline.enabled' => false]);
        $this->row('gimnasio');

        $r = $this->classic('¿La empresa paga el gimnasio?');

        $this->assertSame('escalate', $r['outcome']);
        $this->assertArrayNotHasKey('decline', $r['trace']);
    }

    // ---- L2: the persister re-validates a decline from the trace as written -----------------------------------------

    private function grantedDecline(): TurnOutcome
    {
        $facts = new DeclineFacts(true, 'planner', 'off_domain', false, false, false, false, false, false, null, null,
            ['label' => 'off_domain', 'confidence' => 0.95, 'floor' => 0.9, 'source' => 'llm']);
        $d = DeclineGate::decide($facts);

        return TurnOutcome::decline(DeclineGrant::fromDecision($d), ChatService::DECLINE_MESSAGE, [
            'floor_decision' => ['path' => 'agent_planner', 'outcome' => 'decline', 'decline_reason' => 'off_domain', 'authority_used' => []],
            'decline' => $d->toTrace(),
        ]);
    }

    private function persist(TurnOutcome $o): array
    {
        $session = ChatSession::create(['employee_id' => $this->employee->id, 'started_at' => now(), 'last_activity_at' => now()]);

        return app(TurnPersister::class)->persist($session, $this->employee, 'pregunta', $o);
    }

    public function test_a_valid_decline_persists_as_a_decline(): void
    {
        $r = $this->persist($this->grantedDecline());

        $this->assertSame('decline', $r['outcome']);
        $this->assertSame(0, EscalationCard::count());
    }

    /** @return array<string,array{0:callable(array):array,1:string}> */
    public static function tamperings(): array
    {
        return [
            'a check that did not pass' => [function (array $t) {
                $t['decline']['checks'][3]['pass'] = false;

                return $t;
            }, 'check_failed:D3'],
            'not granted' => [function (array $t) {
                $t['decline']['granted'] = false;

                return $t;
            }, 'not_granted'],
            'another reason' => [function (array $t) {
                $t['decline']['reason'] = 'unanswerable';

                return $t;
            }, 'reason:unanswerable'],
            'unknown source' => [function (array $t) {
                $t['decline']['source'] = 'router';

                return $t;
            }, 'unknown_source'],
            'no checks' => [function (array $t) {
                $t['decline']['checks'] = [];

                return $t;
            }, 'no_checks'],
            'no decline block' => [function (array $t) {
                unset($t['decline']);

                return $t;
            }, 'no_decline_block'],
            'floor_decision says escalate' => [function (array $t) {
                $t['floor_decision']['outcome'] = 'escalate';

                return $t;
            }, 'floor_decision_mismatch'],
            'carries an escalation reason' => [function (array $t) {
                $t['floor_decision']['escalation_reason'] = 'low_confidence';

                return $t;
            }, 'carries_escalation_reason'],
        ];
    }

    #[DataProvider('tamperings')]
    public function test_a_decline_that_does_not_re_validate_is_demoted_to_an_escalation(callable $tamper, string $violation): void
    {
        Log::spy();
        $good = $this->grantedDecline();
        $bad = $good->withTrace($tamper($good->trace));

        $r = $this->persist($bad);

        $this->assertSame('escalate', $r['outcome'], 'fail-closed: more escalation, never less');
        $this->assertSame('low_confidence', $r['escalation_reason']);
        $this->assertSame(ChatService::EMPLOYEE_ESCALATION_MESSAGE, $r['answer']);
        $this->assertNotNull($r['escalation_uuid']);
        $this->assertSame(1, EscalationCard::count());
        $this->assertContains($violation, $r['trace']['decline_demoted']);
        $this->assertSame('escalate', $r['trace']['floor_decision']['outcome']);
        Log::shouldHaveReceived('warning')->withArgs(fn ($m) => $m === 'decline_demoted')->once();
    }

    public function test_the_decline_copy_never_names_a_reason_a_rule_a_pattern_or_a_person(): void
    {
        $this->row('gimnasio');
        $clean = $this->classic('¿La empresa paga el gimnasio?');

        $this->assertSame('decline', $clean['outcome']);
        foreach (['off_domain', 'admin_', 'gimnasio', 'guard', 'router', 'planner'] as $leak) {
            $this->assertStringNotContainsString($leak, $clean['answer'], "decline copy leaks '{$leak}'");
        }
    }
}
