<?php

namespace Tests\Feature;

use App\Jobs\GenerateEscalationExplanationText;
use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Models\Convenio;
use App\Models\Employee;
use App\Models\EscalationCard;
use App\Models\MessageTrace;
use App\Models\Sector;
use App\Models\Territory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * Sprint 7g Item 1 (ADR-0029) — `escalations:backfill-explanations` fills
 * `explanation_facts`/`fix_*` on every PRE-7g card (created before
 * `ChatService::persistTurn()` started computing them), by resolving the
 * originating trace from the assistant message right after the card's
 * `source_message_id`.
 */
class EscalationsBackfillExplanationsTest extends TestCase
{
    use RefreshDatabase;

    private function makeLegacyCard(string $reason, array $trace): EscalationCard
    {
        $territory = Territory::firstOrCreate(['code' => '28'], ['name' => 'Madrid', 'level' => 'provincial', 'aliases' => []]);
        $sector = Sector::firstOrCreate(['name' => 'Sector']);
        $convenio = Convenio::create([
            'numero' => 'BF'.uniqid(), 'name' => 'Convenio Backfill',
            'territory_id' => $territory->id, 'sector_id' => $sector->id,
        ]);
        $employee = Employee::create([
            'email' => 'bf-'.uniqid().'@example.com', 'full_name' => 'Backfill Employee',
            'convenio_id' => $convenio->id, 'job_category_id' => null,
            'territory_id' => $territory->id, 'employment_type' => 'full_time', 'status' => 'active',
        ]);
        $session = ChatSession::create(['employee_id' => $employee->id, 'started_at' => now(), 'last_activity_at' => now()]);
        $userMessage = ChatMessage::create(['session_id' => $session->id, 'role' => 'user', 'content' => 'pregunta legacy']);
        $assistantMessage = ChatMessage::create(['session_id' => $session->id, 'role' => 'assistant', 'content' => 'Un/a compañero/a de Recursos Humanos revisará tu consulta y te responderá.']);
        MessageTrace::create(['message_id' => $assistantMessage->id, 'trace' => $trace]);

        // A genuinely pre-7g card: created directly, WITHOUT explanation_facts
        // (simulating a row that predates this migration's default population).
        return EscalationCard::create([
            'chat_session_id' => $session->id,
            'source_message_id' => $userMessage->id,
            'employee_id' => $employee->id,
            'reason' => $reason,
            'status' => 'new',
        ]);
    }

    public function test_backfill_populates_facts_and_fix_link_and_queues_the_ai_paragraph_job(): void
    {
        Bus::fake();

        $card = $this->makeLegacyCard('sensitive_topic', ['guardrail_check' => ['fired' => true, 'reason' => 'sensitive_topic', 'rule' => 'sensitive_topic']]);
        $this->assertNull($card->explanation_facts);

        $this->artisan('escalations:backfill-explanations')->assertExitCode(0);

        $card->refresh();
        $this->assertNotNull($card->explanation_facts);
        $this->assertSame('sensitive_topic', $card->explanation_facts['reason']);
        $this->assertSame('pattern_baseline', $card->explanation_facts['sub_outcome']);
        $this->assertSame($card->explanation_facts['fix_action'], $card->fix_action);
        $this->assertSame($card->explanation_facts['fix_link'], $card->fix_link);

        Bus::assertDispatched(GenerateEscalationExplanationText::class, fn ($job) => $job->cardUuid === $card->uuid);
    }

    public function test_dry_run_reports_but_writes_nothing(): void
    {
        Bus::fake();
        $card = $this->makeLegacyCard('off_domain', ['guardrail_check' => ['fired' => true, 'reason' => 'off_domain', 'rule' => 'legal_medical']]);

        $this->artisan('escalations:backfill-explanations', ['--dry-run' => true])->assertExitCode(0);

        $card->refresh();
        $this->assertNull($card->explanation_facts);
        Bus::assertNotDispatched(GenerateEscalationExplanationText::class);
    }

    public function test_skip_ai_writes_facts_but_never_queues_the_job(): void
    {
        Bus::fake();
        $card = $this->makeLegacyCard('sensitive_topic', ['guardrail_check' => ['fired' => true, 'reason' => 'sensitive_topic', 'rule' => 'sensitive_topic']]);

        $this->artisan('escalations:backfill-explanations', ['--skip-ai' => true])->assertExitCode(0);

        $card->refresh();
        $this->assertNotNull($card->explanation_facts);
        Bus::assertNotDispatched(GenerateEscalationExplanationText::class);
    }

    public function test_a_card_already_backfilled_is_never_touched_again(): void
    {
        Bus::fake();
        $card = $this->makeLegacyCard('sensitive_topic', ['guardrail_check' => ['fired' => true, 'reason' => 'sensitive_topic', 'rule' => 'sensitive_topic']]);
        $card->forceFill(['explanation_facts' => ['reason' => 'sensitive_topic', 'sub_outcome' => 'already_done'], 'fix_action' => 'kept'])->save();

        $this->artisan('escalations:backfill-explanations')->assertExitCode(0);

        $card->refresh();
        $this->assertSame('already_done', $card->explanation_facts['sub_outcome']);
        $this->assertSame('kept', $card->fix_action);
        Bus::assertNotDispatched(GenerateEscalationExplanationText::class);
    }
}
