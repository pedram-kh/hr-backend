<?php

namespace Tests\Feature;

use App\Models\AnswerModelSetting;
use App\Models\Convenio;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Employee;
use App\Models\EscalationCard;
use App\Models\ReferenceFact;
use App\Models\Sector;
use App\Models\Territory;
use App\Models\Topic;
use App\Services\Agent\AgentChatService;
use App\Services\Agent\PlannerClient;
use App\Services\Agent\PlannerUnavailableException;
use App\Services\Agent\Tool;
use App\Services\Agent\ToolRegistry;
use App\Services\Agent\ToolResult;
use App\Services\Agent\Tools\GeneralKnowledgeTool;
use App\Services\Agent\TurnState;
use App\Services\Answer\TurnOutcome;
use App\Services\ChatService;
use App\Services\ExtractionClient;
use App\Services\GuardrailPolicy;
use Carbon\Carbon;
use Database\Seeders\DocumentTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Slice 13c, build step 8 — the LANE-ON golden traces (plan.md §E.12). The 25 Sprint-13 fixtures (lane off) are untouched and
 * keep proving the off path is byte-identical; these six record what a turn looks like with the lane AND the model-knowledge
 * sub-flag on, end to end through the real `AgentChatService`, the real `GeneralKnowledgeTool`, and the real provider-registered
 * rule engine (availability → tool → post-check → shape check → finish). Only the two outside edges are scripted: the planner
 * and hr-ai (`/general-knowledge`, `/ground`); `convenio_search` is a stand-in that hands the lane a corpus verdict.
 *
 *   26 model-basis answer after an abstention        29 shape-check block (S1 names an article)
 *   27 web-basis answer after a Check-A miss         30 pre-screen denies a quantity question after an abstention
 *   28 post-check block (F1 figure)                  31 a verified reference fact settles the turn (precedence)
 *
 * Two-run discipline of `Sprint13GoldenTraceTest`: a missing fixture is WRITTEN and the test FAILS once.
 */
class Sprint13cLaneGoldenTraceTest extends TestCase
{
    use RefreshDatabase;

    private const MODEL_DRAFT = 'La excedencia es una situación en la que el contrato de trabajo queda suspendido durante un tiempo, sin que la persona trabajadora preste servicios. Sirve para atender necesidades personales o familiares. Para saber cómo se aplica en tu caso, consulta tu convenio o pregunta a Recursos Humanos.';

    private const WEB_DRAFT = 'La vida laboral es un documento de la Seguridad Social que recoge los periodos en los que una persona ha estado dada de alta. Puedes pedirla por internet. Para saber qué refleja en tu caso, consulta tu convenio o pregunta a Recursos Humanos.';

    private Territory $territory;

    private Sector $sector;

    /** @var list<string> the general_knowledge/ground calls the scripted hr-ai saw */
    private array $aiCalls = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DocumentTypeSeeder::class);
        $this->territory = Territory::create(['code' => '31', 'name' => 'Navarra', 'level' => 'provincial', 'aliases' => []]);
        $this->sector = Sector::create(['name' => 'Hostelería', 'aliases' => []]);
        $setting = new AnswerModelSetting(['provider' => 'claude']);
        $setting->id = 1;
        $setting->save();
        $setting->setKey('sk-test-key-abcd', null);

        config(['hr.general_lane.enabled' => true, 'hr.general_lane.model_knowledge' => true]);
        GuardrailPolicy::flush();
    }

    // ---- 26 ------------------------------------------------------------

    public function test_26_model_basis_answer_after_an_abstention(): void
    {
        $this->scriptAi(['answer' => self::MODEL_DRAFT, 'sources' => [['kind' => 'model_knowledge', 'title' => 'conocimiento general']]]);

        $r = $this->turn('26', '¿Qué es una excedencia?', $this->abstention());

        $this->assertSame('answer', $r['outcome']);
        $this->assertSame(['general_knowledge'], $r['authority_used']);
        $this->assertSame([], $r['citations']);
        $this->assertStringContainsString('sin una fuente verificable', $r['answer']);
        $this->assertSame(['general_knowledge'], $this->aiCalls, 'one draft call, and NO /ground for a model-basis answer');
        $this->assertGolden('26_lane_model_answer', $r);
    }

    // ---- 27 ------------------------------------------------------------

    public function test_27_web_basis_answer_after_a_check_a_miss(): void
    {
        $this->scriptAi([
            'answer' => self::WEB_DRAFT,
            'sources' => [['kind' => 'web', 'id' => 'seg-social-vida-laboral', 'title' => 'Seguridad Social — Vida laboral', 'url' => 'https://www.seg-social.es/wps/portal/vida-laboral', 'excerpt' => 'La vida laboral recoge los periodos de alta.']],
            'fetches' => [['url' => 'https://www.seg-social.es/wps/portal/vida-laboral', 'status' => 200, 'error' => null]],
        ], ground: ['grounded' => true, 'claims' => [], 'ungrounded' => []]);

        $r = $this->turn('27', '¿Qué es la vida laboral?', $this->checkAMiss());

        $this->assertSame('answer', $r['outcome']);
        $this->assertStringContainsString('Información general', $r['answer']);
        $this->assertStringNotContainsString('sin una fuente verificable', $r['answer'], 'the web caveat, not the model caveat');
        $this->assertSame(['general_knowledge', 'ground'], $this->aiCalls);
        $this->assertGolden('27_lane_web_answer', $r);
    }

    // ---- 28 ------------------------------------------------------------

    public function test_28_post_check_block_on_a_figure(): void
    {
        $this->scriptAi(['answer' => 'La excedencia es una suspensión del contrato que puede durar hasta 5 años. Consulta tu convenio o pregunta a Recursos Humanos.', 'sources' => [['kind' => 'model_knowledge', 'title' => 'x']]]);

        $r = $this->turn('28', '¿Qué es una excedencia?', $this->abstention());

        $this->assertTrue($r['escalated']);
        $this->assertSame('general_lane_blocked', $r['escalation_reason']);
        $this->assertSame('pass', $this->laneTrace($r)['shape']['verdict'] ?? 'pass');
        $this->assertSame('figure', $r['trace']['agent']['general_lane_blocked']['sub']);
        $this->assertSame('figure', $this->cardSub($r));
        $this->assertGolden('28_lane_postcheck_block', $r);
    }

    // ---- 29 ------------------------------------------------------------

    public function test_29_shape_check_block_on_a_named_article(): void
    {
        $this->scriptAi(['answer' => 'La excedencia es una suspensión del contrato que aparece recogida en el artículo 46 del Estatuto de los Trabajadores y permite dejar de trabajar un tiempo. Consulta tu convenio o pregunta a Recursos Humanos.', 'sources' => [['kind' => 'model_knowledge', 'title' => 'x']]]);

        $r = $this->turn('29', '¿Qué es una excedencia?', $this->abstention());

        $this->assertTrue($r['escalated']);
        $this->assertSame('general_lane_blocked', $r['escalation_reason']);
        $this->assertContains('S1', $this->laneTrace($r)['shape']['rule_ids'] ?? []);
        $this->assertSame('shape', $r['trace']['agent']['general_lane_blocked']['sub']);
        $this->assertSame('shape', $this->cardSub($r), 'the card names the shape block, not the question pre-screen');
        $this->assertGolden('29_lane_shape_block', $r);
    }

    // ---- 30 ------------------------------------------------------------

    public function test_30_prescreen_denies_a_quantity_question_after_an_abstention(): void
    {
        $this->scriptAi(['answer' => 'no debe llamarse', 'sources' => []], expectNone: true);

        $r = $this->turn('30', '¿Cuántos días de excedencia me corresponden?', $this->abstention(), plan: ['convenio_search', 'general_knowledge', 'finalize']);

        $this->assertSame([], $this->aiCalls, 'the lane tool never ran');
        $this->assertTrue($r['escalated']);
        $this->assertNotSame('general_lane_blocked', $r['escalation_reason'], 'the corpus escalation stands');
        $this->assertGolden('30_lane_prescreen_deny_after_abstention', $r);
    }

    // ---- 31 ------------------------------------------------------------

    public function test_31_a_verified_reference_fact_settles_the_turn_before_the_lane(): void
    {
        $this->scriptAi(['answer' => 'no debe llamarse', 'sources' => []], expectNone: true);
        $convenio = $this->convenio('31');
        $topic = Topic::firstOrCreate(['name' => 'periodo de prueba'], ['status' => 'approved']);
        $doc = Document::create([
            'uuid' => $this->uuid('doc-31'), 'title' => 'Periodos de prueba (referencia)', 'storage_path' => 'fake/golden-31',
            'convenio_id' => $convenio->id, 'document_type_id' => DocumentType::where('code', 'convenio_text')->value('id'),
            'authority_level' => 'official_convenio', 'retrieval_status' => 'active', 'language' => 'es', 'tagging_status' => 'verified',
        ]);
        ReferenceFact::create([
            'convenio_id' => $convenio->id, 'topic_id' => $topic->id, 'job_category_id' => null, 'group_label' => null,
            'value' => 'periodo de prueba 90/75/60 días según contrato', 'authority_level' => ReferenceFact::AUTHORITY_LEVEL,
            'source' => 'admin_manual', 'status' => 'verified', 'source_document_id' => $doc->id, 'source_locator' => 'p.1 §1',
        ]);
        $employee = $this->employee($convenio);
        $this->planner([]); // exhausted on purpose: a planner call would throw → classic fallback, visible below

        $r = app(AgentChatService::class)->handle($employee, '¿cuál es mi periodo de prueba?');

        $this->assertSame('answer', $r['outcome']);
        $this->assertSame([], $this->aiCalls);
        $this->assertNotContains('general_knowledge', $r['authority_used'] ?? []);
        $this->assertSame(['round0'], array_column($r['trace']['agent']['steps'], 'type'));
        $this->assertGolden('31_lane_fact_precedence', $r);
    }

    // ---- 32 / 33 — the web attempt fails, the model-knowledge draft takes over ------------

    public function test_32_a_matched_page_with_an_empty_draft_falls_back_to_model_knowledge(): void
    {
        $fetch = ['url' => 'https://www.seg-social.es/wps/portal/x', 'status' => 200, 'error' => null];
        $this->scriptAi(['answer' => '', 'sources' => [], 'basis' => 'web', 'fetches' => [$fetch]], skipWebDraft: ['answer' => self::MODEL_DRAFT, 'sources' => [['kind' => 'model_knowledge', 'title' => 'x']]]);

        $r = $this->turn('32', '¿Qué es la vida laboral?', $this->abstention());

        $this->assertSame('answer', $r['outcome']);
        $this->assertSame(['general_knowledge', 'general_knowledge(skip_web)'], $this->aiCalls, 'no /ground: the web draft was empty');
        $lane = $this->laneTrace($r);
        $this->assertSame('model_knowledge', $lane['basis']);
        $this->assertSame('web_empty_draft', $lane['fallback']['reason']);
        $this->assertTrue($lane['web_attempted']);
        $this->assertStringContainsString('sin una fuente verificable', $r['answer']);
        $this->assertGolden('32_lane_web_empty_fallback', $r);
    }

    public function test_33_a_web_draft_that_fails_grounding_falls_back_to_model_knowledge(): void
    {
        $this->scriptAi([
            'answer' => self::WEB_DRAFT,
            'sources' => [['kind' => 'web', 'id' => 'seg-social-x', 'title' => 'Seguridad Social', 'url' => 'https://www.seg-social.es/x', 'excerpt' => 'otro tema']],
            'fetches' => [['url' => 'https://www.seg-social.es/x', 'status' => 200, 'error' => null]],
        ], ground: ['grounded' => false, 'claims' => [], 'ungrounded' => ['x']], skipWebDraft: ['answer' => self::MODEL_DRAFT, 'sources' => [['kind' => 'model_knowledge', 'title' => 'x']]]);

        $r = $this->turn('33', '¿Qué es la vida laboral?', $this->abstention());

        $this->assertSame('answer', $r['outcome']);
        $this->assertSame(['general_knowledge', 'ground', 'general_knowledge(skip_web)'], $this->aiCalls);
        $this->assertSame('web_ungrounded', $this->laneTrace($r)['fallback']['reason']);
        $this->assertGolden('33_lane_web_ungrounded_fallback', $r);
    }

    public function test_the_fallback_is_model_knowledge_subflag_only(): void
    {
        config(['hr.general_lane.model_knowledge' => false]);
        GuardrailPolicy::flush();
        $this->scriptAi(['answer' => '', 'sources' => [], 'basis' => 'web', 'fetches' => []]);

        $r = $this->turn('34', '¿Qué es la vida laboral?', $this->checkAMiss(), plan: ['convenio_search', 'general_knowledge', 'finalize']);

        $this->assertSame(['general_knowledge'], $this->aiCalls, 'sub-flag off: no second call');
        $this->assertTrue($r['escalated']);
    }

    public function test_when_the_fallback_draft_is_also_unusable_the_web_outcome_stands(): void
    {
        $this->scriptAi(['answer' => '', 'sources' => [], 'basis' => 'web', 'fetches' => []], skipWebDraft: ['answer' => '', 'sources' => []]);

        $r = $this->turn('35', '¿Qué es la vida laboral?', $this->abstention(), plan: ['convenio_search', 'general_knowledge', 'finalize']);

        $this->assertSame(['general_knowledge', 'general_knowledge(skip_web)'], $this->aiCalls);
        $this->assertTrue($r['escalated']);
        $this->assertNotSame('general_lane_blocked', $r['escalation_reason']);
    }

    // =====================================================================
    // harness
    // =====================================================================

    private function convenio(string $n): Convenio
    {
        return Convenio::create(['numero' => '31TEST-13c-'.$n, 'name' => 'Convenio 13c '.$n, 'territory_id' => $this->territory->id, 'sector_id' => $this->sector->id]);
    }

    private function employee(Convenio $c): Employee
    {
        return Employee::create([
            'uuid' => $this->uuid('emp-'.$c->numero), 'email' => 'emp-13c-'.$c->id.'@example.com', 'full_name' => 'Empleada de prueba',
            'convenio_id' => $c->id, 'territory_id' => $this->territory->id, 'employment_type' => 'full_time', 'status' => 'active',
        ]);
    }

    private function uuid(string $seed): string
    {
        $p = str_split(substr(hash('sha256', $seed), 0, 32), 4);

        return "{$p[0]}{$p[1]}-{$p[2]}-{$p[3]}-{$p[4]}-{$p[5]}{$p[6]}{$p[7]}";
    }

    /** @return array<string,mixed> a Check-B abstention: check A passed, no citations, confidence ≤ 0.2 (plan.md §2.2) */
    private function abstention(): array
    {
        return [
            'retrieval_score_floor' => 0.4, 'answer_confidence_floor' => 0.65, 'check_a_retrieval' => true, 'check_b_citations' => false,
            'check_c_confidence_tiebreaker' => ['confidence' => 0.1, 'below_floor' => true, 'used_as_gate' => false],
            'figure_grounding' => ['checked' => true, 'figures' => [], 'grounded' => true, 'ungrounded' => []],
            'grounding' => ['checked' => false, 'reason' => 'short-circuited before /ground'],
            'authority_used' => [], 'outcome' => 'escalate', 'escalation_reason' => 'low_confidence', 'note' => 'no valid citations (Check B failed)',
        ];
    }

    /** @return array<string,mixed> */
    private function checkAMiss(): array
    {
        return ['check_a_retrieval' => false, 'outcome' => 'escalate', 'escalation_reason' => 'low_confidence'];
    }

    /**
     * One lane turn: `convenio_search` (a stand-in returning the given corpus verdict), then `general_knowledge`, then finalize
     * (only reached when the lane did not settle the turn).
     *
     * @param  array<string,mixed>  $floor
     * @param  list<string>  $plan
     * @return array<string,mixed>
     */
    private function turn(string $n, string $question, array $floor, array $plan = ['convenio_search', 'general_knowledge']): array
    {
        $employee = $this->employee($this->convenio($n));
        $corpus = new Sprint13cGoldenStubTool('convenio_search', fn () => new ToolResult(
            ToolResult::NO_MATERIAL,
            terminalOutcome: new TurnOutcome('escalate', ChatService::ESCALATION_MESSAGE, [], ['floor_decision' => $floor, 'retrieval' => []], 'low_confidence'),
            plannerSummary: ['status' => ($floor['check_a_retrieval'] ?? true) ? 'abstained' : 'check_a_failed'],
        ));
        $this->app->singleton(ToolRegistry::class, function ($app) use ($corpus) {
            $registry = new ToolRegistry;
            $registry->register($corpus);
            $registry->register($app->make(GeneralKnowledgeTool::class));

            return $registry;
        });
        $this->planner(array_map(fn (string $t) => ['calls' => [['id' => 't-'.$t, 'tool' => $t, 'input' => $t === 'finalize' ? ['use' => []] : []]]], $plan));

        return app(AgentChatService::class)->handle($employee, $question);
    }

    /** @param  list<array<string,mixed>>  $responses */
    private function planner(array $responses): void
    {
        $planner = new class($responses) implements PlannerClient
        {
            private int $i = 0;

            public function __construct(private readonly array $responses) {}

            public function plan(string $question, array $scopeSummary, array $window, array $toolDefinitions, array $priorSteps): array
            {
                if (! isset($this->responses[$this->i])) {
                    throw new PlannerUnavailableException('script exhausted');
                }

                return $this->responses[$this->i++] + ['stop_reason' => 'tool_use', 'model' => null, 'request_id' => null, 'prompt_version' => null, 'tokens' => [], 'ms' => 0];
            }
        };
        $this->app->instance(PlannerClient::class, $planner);
    }

    /**
     * @param  array{answer:string,sources:list<array<string,mixed>>,fetches?:list<array<string,mixed>>,basis?:string}  $draft
     * @param  array<string,mixed>|null  $ground
     */
    private function scriptAi(array $draft, ?array $ground = null, bool $expectNone = false, ?array $skipWebDraft = null): void
    {
        $this->aiCalls = [];
        $envelope = $this->envelope($draft);
        $skipEnvelope = $skipWebDraft === null ? null : $this->envelope($skipWebDraft);
        $this->app->instance(ExtractionClient::class, new class($envelope, $ground, $this->aiCalls, $expectNone, $skipEnvelope) extends ExtractionClient
        {
            public function __construct(private array $envelope, private ?array $ground, private array &$calls, private bool $expectNone, private ?array $skipEnvelope) {}

            public function generalKnowledge(string $question, array $catalogue, array $domains, string $decryptedKey, array $providerConfig, bool $modelKnowledge = false, bool $skipWeb = false): array
            {
                if ($this->expectNone) {
                    throw new \RuntimeException('the lane must not have been reached');
                }
                if ($skipWeb) {
                    $this->calls[] = 'general_knowledge(skip_web)';

                    return $this->skipEnvelope ?? throw new \RuntimeException('no skip_web draft scripted');
                }
                $this->calls[] = 'general_knowledge';

                return $this->envelope;
            }

            public function ground(string $question, string $answer, array $chunks, string $decryptedKey, array $providerConfig): array
            {
                $this->calls[] = 'ground';

                return $this->ground ?? throw new \RuntimeException('/ground must not be called for this case');
            }
        });
    }

    /**
     * @param  array{answer:string,sources:list<array<string,mixed>>,fetches?:list<array<string,mixed>>,basis?:string}  $draft
     * @return array<string,mixed>
     */
    private function envelope(array $draft): array
    {
        return [
            'answer' => $draft['answer'],
            'sources' => $draft['sources'],
            'trace_fragment' => [
                'model' => 'claude-sonnet-5', 'general_knowledge_ms' => 900, 'prompt_tokens' => 400, 'completion_tokens' => 150, 'cost_usd' => 0.0034,
                'basis' => $draft['basis'] ?? (($draft['sources'][0]['kind'] ?? null) === 'web' ? 'web' : 'model_knowledge'),
                'prompt_sha256' => str_repeat('a', 64), 'fetches' => $draft['fetches'] ?? [],
            ],
        ];
    }

    private function cardSub(array $r): ?string
    {
        $card = EscalationCard::where('uuid', $r['escalation_uuid'])->first();

        return $card?->explanation_facts['sub_outcome'] ?? null;
    }

    /** @return array<string,mixed> */
    private function laneTrace(array $r): array
    {
        return $r['trace']['general_lane'] ?? [];
    }

    // ---- the comparator (same discipline as Sprint13GoldenTraceTest) ----

    /** Keys whose value is a clock/sequence artefact, not behaviour. */
    private function scrub(mixed $v): mixed
    {
        if (! is_array($v)) {
            return $v;
        }
        $out = [];
        foreach ($v as $k => $x) {
            if (is_string($k) && in_array($k, ['session_uuid', 'fact_uuid', 'message_id', 'escalation_uuid', 'ms', 'duration_ms', 'started_at', 'created_at', 'updated_at', 'turn_id'], true)) {
                continue;
            }
            if (is_string($k) && preg_match('/(^|_)(convenio|document|fact|topic|table|territory|job_category)_ids?$/', $k) && is_int($x)) {
                $out[$k] = '#'.$k;

                continue;
            }
            if ($k === 'as_of_date' && is_string($x) && $x === Carbon::today()->toDateString()) {
                // `ChatService` stamps today on every turn; a literal date fails the day after it was recorded
                // (found at the 13c close: the goldens recorded on 2026-09-30 failed on the 2026-10-01 deploy).
                $out[$k] = '#as_of_date:today';

                continue;
            }
            $out[$k] = is_string($x) ? preg_replace('/\((fact_id|document_id|convenio_id|topic_id) \d+\)/', '($1 #n)', $x) : $this->scrub($x);
        }

        return $out;
    }

    /** @param  array<string,mixed>  $result */
    private function assertGolden(string $case, array $result): void
    {
        $card = null;
        if (($result['escalated'] ?? false) && ($result['escalation_uuid'] ?? null)) {
            $row = EscalationCard::where('uuid', $result['escalation_uuid'])->first();
            $card = $row ? ['reason' => $row->reason, 'explanation_facts' => $row->explanation_facts, 'fix_action' => $row->fix_action, 'fix_surface' => $row->fix_surface] : null;
        }
        $payload = $this->scrub([
            'outcome' => $result['outcome'], 'escalated' => $result['escalated'], 'escalation_reason' => $result['escalation_reason'],
            'answer' => $result['answer'], 'citations' => $result['citations'], 'categories' => $result['categories'] ?? null,
            'authority_used' => $result['authority_used'], 'trace' => $result['trace'], 'escalation_card_facts' => $card,
        ]);
        $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";

        $path = __DIR__.'/../Fixtures/golden-traces-13c/'.$case.'.json';
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        if (! file_exists($path)) {
            file_put_contents($path, $encoded);
            $this->fail("lane golden recorded for '{$case}' at {$path} — re-run to verify (this run only captured the baseline).");
        }
        $this->assertJsonStringEqualsJsonString(file_get_contents($path), $encoded, "lane golden for '{$case}' changed — see {$path}");
    }
}

final class Sprint13cGoldenStubTool implements Tool
{
    /** @param  \Closure():ToolResult  $run */
    public function __construct(private readonly string $toolName, private readonly \Closure $run) {}

    public function name(): string
    {
        return $this->toolName;
    }

    public function definition(): array
    {
        return ['name' => $this->toolName, 'description' => 'test double', 'input_schema' => ['type' => 'object']];
    }

    public function countsAsClarification(): bool
    {
        return false;
    }

    public function run(array $input, TurnState $state): ToolResult
    {
        return ($this->run)();
    }
}
