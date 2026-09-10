<?php

namespace Tests\Feature;

use App\Models\Convenio;
use App\Models\Employee;
use App\Models\EscalationCard;
use App\Models\Sector;
use App\Models\Territory;
use App\Services\ChatService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Sprint 7g Item 1 (ADR-0029) — the employee-side scan: whatever the reason,
 * whatever the sub-outcome, the employee sees ONE fixed neutral message,
 * verbatim, with no reason token, no ids, no other-employee name, no scope
 * hint. Covers every guardrail-baseline reason reachable WITHOUT an AI
 * provider (they short-circuit before any /route or /synthesise call — the
 * exact reason this test needs no `ExtractionClient` fake).
 */
class EmployeeEscalationMessageScanTest extends TestCase
{
    use RefreshDatabase;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $territory = Territory::create(['code' => '28', 'name' => 'Madrid', 'level' => 'provincial', 'aliases' => []]);
        $sector = Sector::create(['name' => 'Sector', 'aliases' => []]);
        $convenio = Convenio::create([
            'numero' => '28TEST0001', 'name' => 'Convenio Test',
            'territory_id' => $territory->id, 'sector_id' => $sector->id,
        ]);
        $this->employee = Employee::create([
            'email' => 'scan-'.uniqid().'@example.com', 'full_name' => 'Empleada Escaneada',
            'convenio_id' => $convenio->id, 'job_category_id' => null,
            'territory_id' => $territory->id, 'employment_type' => 'full_time', 'status' => 'active',
        ]);
    }

    /** @return array<string, array{0:string, 1:string}> [question, expected escalation_reason] */
    public static function baselineQuestions(): array
    {
        return [
            'sensitive_topic (harassment)' => ['tengo un problema de acoso laboral con mi jefe', 'sensitive_topic'],
            'sensitive_topic (disciplinary)' => ['me han abierto un expediente disciplinario', 'sensitive_topic'],
            'off_domain (legal advice)' => ['necesito consejo legal sobre esto, ¿debería contratar un abogado?', 'off_domain'],
            'off_domain (medical advice)' => ['¿qué tratamiento médico debo seguir para mis síntomas?', 'off_domain'],
            // Names a specific third party — the strongest "no other person's
            // name" leak test: if the fixed message ever regressed to echo
            // any part of the question, "Pedro" or "García" would appear.
            'off_domain (other employee data)' => ['¿cuánto gana Pedro García en la empresa?', 'off_domain'],
        ];
    }

    #[DataProvider('baselineQuestions')]
    public function test_employee_sees_only_the_fixed_neutral_message(string $question, string $expectedReason): void
    {
        $result = app(ChatService::class)->handleMessage($this->employee, $question);

        $this->assertSame('escalate', $result['outcome']);
        $this->assertSame($expectedReason, $result['escalation_reason']);

        // THE invariant: always the one fixed message, verbatim.
        $this->assertSame(ChatService::EMPLOYEE_ESCALATION_MESSAGE, $result['answer']);

        // No reason token, no rule name, leaked into the text shown.
        $this->assertStringNotContainsString('sensitive_topic', $result['answer']);
        $this->assertStringNotContainsString('off_domain', $result['answer']);
        $this->assertStringNotContainsString('legal_medical', $result['answer']);
        $this->assertStringNotContainsString('other_employee_data', $result['answer']);

        // No third-party name (the guardrail baseline privacy case) leaked.
        $this->assertStringNotContainsString('Pedro', $result['answer']);
        $this->assertStringNotContainsString('García', $result['answer']);

        // No id/number of any kind leaked (no document id, no fact id, no uuid
        // fragment) — the message is pure fixed prose, no digits at all.
        $this->assertDoesNotMatchRegularExpression('/\d/', $result['answer']);

        // No citations either — an escalation never carries a source.
        $this->assertSame([], $result['citations']);
    }

    public function test_the_fixed_message_is_identical_byte_for_byte_across_every_distinct_reason(): void
    {
        $seen = [];
        foreach (self::baselineQuestions() as [$question, $expectedReason]) {
            $result = app(ChatService::class)->handleMessage($this->employee, $question);
            $seen[] = $result['answer'];
        }

        $this->assertCount(1, array_unique($seen), 'every escalation reason must produce byte-identical employee text');
        $this->assertSame(ChatService::EMPLOYEE_ESCALATION_MESSAGE, $seen[0]);
    }

    /** HR still gets the reason + facts on the card — only the EMPLOYEE'S view is neutral. */
    public function test_hr_side_card_still_records_the_real_reason_and_facts(): void
    {
        app(ChatService::class)->handleMessage($this->employee, 'tengo un problema de acoso laboral con mi jefe');

        $card = EscalationCard::firstOrFail();
        $this->assertSame('sensitive_topic', $card->reason);
        $this->assertNotNull($card->explanation_facts);
        $this->assertSame('pattern_baseline', $card->explanation_facts['sub_outcome']);
        $this->assertNotEmpty($card->explanation_facts['found']);
    }
}
