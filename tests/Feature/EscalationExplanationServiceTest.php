<?php

namespace Tests\Feature;

use App\Models\AnswerModelSetting;
use App\Models\Convenio;
use App\Models\Employee;
use App\Models\EscalationCard;
use App\Models\Sector;
use App\Models\Territory;
use App\Services\ExtractionClient;
use App\Support\EscalationExplainer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sprint 7g Item 1 (ADR-0029) — `EscalationExplanationService::generateFor()`.
 * Reuses hr-ai's EXISTING `/synthesise` endpoint (via `ExtractionClient`),
 * with the cheap ROUTER_MODEL. On any failure — no key configured, provider
 * error, or a failed no-new-claims check — `explanation_text` stays null and
 * the card falls back to `EscalationExplainer::factsToSentences()` on
 * display. The fix action/link are NEVER touched by this service (they are
 * set once, deterministically, at card creation).
 */
class EscalationExplanationServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeCard(): EscalationCard
    {
        $territory = Territory::create(['code' => '28', 'name' => 'Madrid', 'level' => 'provincial', 'aliases' => []]);
        $sector = Sector::create(['name' => 'Sector', 'aliases' => []]);
        $convenio = Convenio::create([
            'numero' => 'EX'.uniqid(), 'name' => 'Convenio Explanation',
            'territory_id' => $territory->id, 'sector_id' => $sector->id,
        ]);
        $employee = Employee::create([
            'email' => 'exp-'.uniqid().'@example.com', 'full_name' => 'Explained Employee',
            'convenio_id' => $convenio->id, 'job_category_id' => null,
            'territory_id' => $territory->id, 'employment_type' => 'full_time', 'status' => 'active',
        ]);

        $facts = EscalationExplainer::explain('salary_coverage_gap', [
            'profile' => ['employee_uuid' => $employee->uuid ?? null],
            'salary' => ['note' => 'no salary table for this convenio (coverage gap — salary may be PDF-only)'],
        ]);

        return EscalationCard::create([
            'employee_id' => $employee->id,
            'reason' => 'salary_coverage_gap',
            'status' => 'new',
            'explanation_facts' => $facts,
            'fix_action' => $facts['fix_action'],
            'fix_surface' => $facts['fix_surface'],
            'fix_link' => $facts['fix_link'],
        ]);
    }

    private function configureAnswerModel(): void
    {
        AnswerModelSetting::query()->delete();
        $s = new AnswerModelSetting(['provider' => 'claude']);
        $s->id = 1;
        $s->save();
        $s->setKey('sk-test-key-1234', null);
    }

    public function test_a_faithful_paragraph_is_persisted(): void
    {
        $this->configureAnswerModel();
        $card = $this->makeCard();

        $fake = new class extends ExtractionClient
        {
            public function synthesise(string $q, array $ch, string $k, array $c): array
            {
                return [
                    // Faithful restatement — including the ADR-0014 reference
                    // number the facts themselves carry (`stopped_reason`),
                    // since the guard's "no dropped fact number" check treats
                    // any digit in the facts, including an ADR citation, as
                    // one that must be represented.
                    'answer' => 'El empleado preguntó por su salario, pero no existe ninguna tabla salarial cargada para su convenio. '.
                        'Por eso se escaló: sin fila estructurada, el sistema nunca adivina una cifra, conforme a ADR-0014, y conviene cargar o convertir la tabla salarial de este convenio.',
                    'citations' => [],
                    'confidence' => 0.9,
                    'authority_used' => [],
                    'trace_fragment' => ['cost_usd' => 0.000123, 'model' => 'claude-haiku-4-5'],
                ];
            }
        };
        $this->app->instance(ExtractionClient::class, $fake);

        app(\App\Services\EscalationExplanationService::class)->generateFor($card);

        $card->refresh();
        $this->assertNotNull($card->explanation_text);
        $this->assertStringContainsString('tabla salarial', $card->explanation_text);
        // Fix action/link untouched by this service — they were set at creation.
        $this->assertSame('Documentos', $card->fix_surface);
    }

    public function test_a_provider_error_leaves_explanation_text_null(): void
    {
        $this->configureAnswerModel();
        $card = $this->makeCard();

        $fake = new class extends ExtractionClient
        {
            public function synthesise(string $q, array $ch, string $k, array $c): array
            {
                return ['error' => 'provider_error', 'detail' => 'timeout', 'trace_fragment' => []];
            }
        };
        $this->app->instance(ExtractionClient::class, $fake);

        app(\App\Services\EscalationExplanationService::class)->generateFor($card);

        $card->refresh();
        $this->assertNull($card->explanation_text);
    }

    public function test_a_paragraph_that_invents_a_new_number_is_rejected_and_left_null(): void
    {
        $this->configureAnswerModel();
        $card = $this->makeCard();

        $fake = new class extends ExtractionClient
        {
            public function synthesise(string $q, array $ch, string $k, array $c): array
            {
                return [
                    'answer' => 'El empleado preguntó por su salario. No existe tabla salarial, pero debería cobrar 1500 euros. Se recomienda cargarla.',
                    'citations' => [], 'confidence' => 0.9, 'authority_used' => [], 'trace_fragment' => ['cost_usd' => 0.0001],
                ];
            }
        };
        $this->app->instance(ExtractionClient::class, $fake);

        app(\App\Services\EscalationExplanationService::class)->generateFor($card);

        $card->refresh();
        $this->assertNull($card->explanation_text);
    }

    public function test_no_answer_model_configured_never_calls_synthesise_and_leaves_text_null(): void
    {
        AnswerModelSetting::query()->delete();
        $card = $this->makeCard();

        $fake = new class extends ExtractionClient
        {
            public function synthesise(string $q, array $ch, string $k, array $c): array
            {
                throw new \RuntimeException('must not be called when no answer model is configured');
            }
        };
        $this->app->instance(ExtractionClient::class, $fake);

        app(\App\Services\EscalationExplanationService::class)->generateFor($card);

        $card->refresh();
        $this->assertNull($card->explanation_text);
    }

    public function test_a_card_with_no_facts_never_calls_synthesise(): void
    {
        $this->configureAnswerModel();

        $territory = Territory::create(['code' => '28', 'name' => 'Madrid', 'level' => 'provincial', 'aliases' => []]);
        $sector = Sector::create(['name' => 'Sector', 'aliases' => []]);
        $convenio = Convenio::create(['numero' => 'NF'.uniqid(), 'name' => 'X', 'territory_id' => $territory->id, 'sector_id' => $sector->id]);
        $employee = Employee::create([
            'email' => 'nf-'.uniqid().'@example.com', 'full_name' => 'No Facts',
            'convenio_id' => $convenio->id, 'job_category_id' => null,
            'territory_id' => $territory->id, 'employment_type' => 'full_time', 'status' => 'active',
        ]);
        $card = EscalationCard::create(['employee_id' => $employee->id, 'reason' => 'low_confidence', 'status' => 'new']);

        $fake = new class extends ExtractionClient
        {
            public function synthesise(string $q, array $ch, string $k, array $c): array
            {
                throw new \RuntimeException('must not be called when the card has no explanation_facts');
            }
        };
        $this->app->instance(ExtractionClient::class, $fake);

        app(\App\Services\EscalationExplanationService::class)->generateFor($card);

        $card->refresh();
        $this->assertNull($card->explanation_text);
    }
}
