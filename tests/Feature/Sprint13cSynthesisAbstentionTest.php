<?php

namespace Tests\Feature;

use App\Console\Commands\AnswerGate;
use App\Models\AnswerModelSetting;
use App\Models\Convenio;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Employee;
use App\Models\Sector;
use App\Models\Territory;
use App\Services\Agent\Rules\CorpusMiss;
use App\Services\Answer\SynthesisAbstention;
use App\Services\Answer\TurnOutcome;
use App\Services\ChatService;
use App\Services\ExtractionClient;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Slice 13c (S3c blocker: LP-14 / LP-45). A synthesis abstention that still CITES a related source ("No dispongo de
 * información suficiente… [Fuente 1]") passes Check B and was persisted as the corpus answer; the lane never opened. Proves:
 *
 *   1. with the sub-flag on, /synthesise is asked for the structured `abstained` flag (top-level body key, NOT inside
 *      provider_config) and a flagged abstention escalates (`low_confidence`) and is recognised by `CorpusMiss`;
 *   2. with the sub-flag off the request body, the trace and the outcome are exactly what they were;
 *   3. `abstained=false` answers as before; the phrase is a fallback only (gate, flag never recorded);
 *   4. `answer:gate` classifies an abstention as `abstain`, never `answer`.
 */
class Sprint13cSynthesisAbstentionTest extends TestCase
{
    use RefreshDatabase;

    private Employee $employee;

    private int $docId;

    private int $chunkId;

    private SynthAbstentionFake $fake;

    private const LP14 = 'No dispongo de información suficiente en las fuentes proporcionadas para definir qué es la Inspección de Trabajo. Las fuentes solo mencionan a la Inspección de Trabajo y Seguridad Social [Fuente 1], pero no contienen una definición.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);

        $territory = Territory::create(['code' => '01', 'name' => 'Álava', 'level' => 'provincial', 'aliases' => []]);
        $sector = Sector::create(['name' => 'Sector', 'aliases' => []]);
        $convenio = Convenio::create(['numero' => '13CABS', 'name' => 'Convenio abstención', 'territory_id' => $territory->id, 'sector_id' => $sector->id]);
        $this->employee = Employee::create([
            'email' => 'abstention@example.com', 'full_name' => 'Abstention Worker', 'convenio_id' => $convenio->id,
            'territory_id' => $territory->id, 'employment_type' => 'full_time', 'status' => 'active',
        ]);
        $type = DocumentType::firstOrCreate(['code' => 'convenio_text'], ['name' => 'Convenio (texto)']);
        $this->docId = Document::create([
            'title' => 'Convenio abstención', 'storage_path' => 'test/abs.pdf', 'convenio_id' => $convenio->id,
            'document_type_id' => $type->id, 'retrieval_status' => 'active', 'authority_level' => 'official_convenio',
            'language' => 'es', 'tagging_status' => 'verified',
        ])->id;
        $this->chunkId = (int) DB::table('document_chunks')->insertGetId([
            'document_id' => $this->docId, 'chunk_index' => 0, 'page_from' => 1, 'page_to' => 1,
            'content' => 'La Inspección de Trabajo y Seguridad Social accede a los expedientes de regulación temporal de empleo.',
            'token_count' => 14, 'convenio_id' => $convenio->id, 'retrieval_status' => 'active', 'authority_level' => 'official_convenio',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->fake = new SynthAbstentionFake;
        $this->app->instance(ExtractionClient::class, $this->fake);
        $s = new AnswerModelSetting(['provider' => 'claude']);
        $s->id = 1;
        $s->save();
        $s->setKey('sk-test-key-abcd', null);
    }

    private function prime(string $answer, array $extra = []): void
    {
        $this->fake->retrieveResponse = ['chunks' => [[
            'id' => $this->chunkId, 'document_id' => $this->docId, 'page_from' => 1, 'page_to' => 1,
            'content' => 'La Inspección de Trabajo y Seguridad Social accede a los expedientes.', 'score' => 0.9, 'authority_level' => 'official_convenio',
        ]], 'eligible_total' => 1];
        $this->fake->synthesiseResponse = [
            'answer' => $answer,
            'citations' => [['chunk_id' => $this->chunkId, 'document_id' => $this->docId, 'page_from' => 1, 'page_to' => 1, 'authority_level' => 'official_convenio']],
            'grounding_signal' => ['grounded' => true, 'citation_count' => 1, 'top_chunk_score' => 0.9],
            'confidence' => 0.6, 'authority_used' => ['official_convenio'], 'trace_fragment' => [],
        ] + $extra;
    }

    private function laneConfig(bool $enabled, bool $modelKnowledge): void
    {
        config(['hr.general_lane.enabled' => $enabled, 'hr.general_lane.model_knowledge' => $modelKnowledge]);
    }

    private function ask(): array
    {
        return app(ChatService::class)->handleMessage($this->employee, '¿Qué es la Inspección de Trabajo?');
    }

    // ---- 1. sub-flag on ------------------------------------------------------

    public function test_a_flagged_abstention_that_cites_a_source_escalates_and_is_handed_to_the_lane(): void
    {
        $this->laneConfig(true, true);
        $this->prime(self::LP14, ['abstained' => true, 'abstained_by' => 'model_flag']);

        $r = $this->ask();

        $this->assertTrue($this->fake->lastSynthConfig['report_abstention'] ?? false, 'the flag is requested when the sub-flag is on');
        $this->assertSame('escalate', $r['outcome']);
        $this->assertSame('low_confidence', $r['escalation_reason']);
        $floor = $r['trace']['floor_decision'];
        $this->assertTrue($floor['check_b_citations'], 'the draft DID cite a source — the old structural test cannot see this');
        $this->assertSame(['flag' => true, 'by' => 'model_flag'], $floor['synthesis_abstained']);
        $this->assertSame('synthesis abstained (flag)', $floor['note']);
        $this->assertSame(['abstained' => true, 'by' => 'model_flag'], $r['trace']['synthesis']['abstention']);

        $outcome = new TurnOutcome('escalate', '', [], $r['trace'], 'low_confidence');
        $this->assertSame(CorpusMiss::SYNTHESIS_ABSTENTION, CorpusMiss::classify($outcome, true));
        $this->assertNull(CorpusMiss::classify($outcome, false), 'sub-flag off never hands an abstention over');
    }

    public function test_the_abstention_hand_over_still_respects_the_fallback_exclusion_and_the_escalate_requirement(): void
    {
        $base = ['check_a_retrieval' => true, 'outcome' => 'escalate', 'escalation_reason' => 'low_confidence', 'synthesis_abstained' => ['flag' => true, 'by' => 'model_flag']];
        $mk = fn (array $floor, string $o = 'escalate') => new TurnOutcome($o, '', [], ['floor_decision' => $floor], $o === 'escalate' ? 'low_confidence' : null);

        $this->assertSame(CorpusMiss::SYNTHESIS_ABSTENTION, CorpusMiss::classify($mk($base), true));
        $this->assertNull(CorpusMiss::classify($mk($base + ['fallback' => ['x' => 1]]), true));
        $this->assertNull(CorpusMiss::classify($mk(['synthesis_abstained' => ['flag' => false]] + $base), true));
        $this->assertNull(CorpusMiss::classify($mk($base, 'answer'), true));
    }

    // ---- 2. sub-flag off: nothing changes ------------------------------------

    public function test_sub_flag_off_does_not_request_the_flag_and_ignores_it_byte_identically(): void
    {
        $this->laneConfig(true, false);
        $this->prime(self::LP14, ['abstained' => true, 'abstained_by' => 'model_flag']);
        // a legitimate grounded answer path needs /ground to pass
        $this->fake->groundResponse = ['grounded' => true, 'claims' => [], 'ungrounded' => [], 'trace_fragment' => []];

        $r = $this->ask();

        $this->assertArrayNotHasKey('report_abstention', $this->fake->lastSynthConfig, 'the request is unchanged with the sub-flag off');
        $this->assertSame('answer', $r['outcome'], 'with the sub-flag off the classic behaviour is kept');
        $this->assertArrayNotHasKey('synthesis_abstained', $r['trace']['floor_decision']);
        $this->assertArrayNotHasKey('abstention', $r['trace']['synthesis']);
    }

    public function test_lane_off_entirely_is_the_same(): void
    {
        $this->laneConfig(false, false);
        $this->prime(self::LP14, ['abstained' => true]);
        $this->fake->groundResponse = ['grounded' => true, 'claims' => [], 'ungrounded' => [], 'trace_fragment' => []];

        $r = $this->ask();

        $this->assertArrayNotHasKey('report_abstention', $this->fake->lastSynthConfig);
        $this->assertSame('answer', $r['outcome']);
        $this->assertArrayNotHasKey('abstention', $r['trace']['synthesis']);
    }

    // ---- 3. abstained=false answers as before --------------------------------

    public function test_abstained_false_answers_and_records_the_declaration(): void
    {
        $this->laneConfig(true, true);
        $this->prime('La Inspección accede a los expedientes [Fuente 1].', ['abstained' => false, 'abstained_by' => 'model_flag']);
        $this->fake->groundResponse = ['grounded' => true, 'claims' => [], 'ungrounded' => [], 'trace_fragment' => []];

        $r = $this->ask();

        $this->assertSame('answer', $r['outcome']);
        $this->assertSame(['abstained' => false, 'by' => 'model_flag'], $r['trace']['synthesis']['abstention']);
        $this->assertArrayNotHasKey('synthesis_abstained', $r['trace']['floor_decision']);
    }

    public function test_the_request_body_carries_report_abstention_top_level_only_when_asked(): void
    {
        config(['services.hr_ai.url' => 'http://hr-ai.test', 'services.hr_ai.internal_token' => 't']);
        Http::fake(['*' => Http::response(['answer' => 'x', 'citations' => [], 'confidence' => 0.1])]);
        $real = new ExtractionClient;
        $cfg = ['provider' => 'claude', 'model' => 'm', 'endpoint' => null];

        $real->synthesise('q', [], 'k', $cfg);
        $real->synthesise('q', [], 'k', $cfg + ['report_abstention' => true]);

        $bodies = Http::recorded()->map(fn ($pair) => $pair[0]->data())->values()->all();
        $this->assertArrayNotHasKey('report_abstention', $bodies[0], 'off = byte-identical request');
        $this->assertSame(['provider' => 'claude', 'model' => 'm', 'endpoint' => null], $bodies[0]['provider_config']);
        $this->assertTrue($bodies[1]['report_abstention']);
        $this->assertArrayNotHasKey('report_abstention', $bodies[1]['provider_config'], 'never inside provider_config (hr-ai /ground etc. share it)');
    }

    // ---- 4. the phrase (fallback) and the gate classification ----------------

    /** @return array<string,array{0:string,1:bool}> */
    public static function phraseProvider(): array
    {
        $cases = json_decode((string) file_get_contents(__DIR__.'/../Fixtures/synthesis-abstention-phrases.json'), true, 512, JSON_THROW_ON_ERROR)['cases'];

        return array_combine(array_column($cases, 'id'), array_map(fn ($c) => [$c['text'], $c['phrase']], $cases));
    }

    #[DataProvider('phraseProvider')]
    public function test_the_phrase_fallback_matches_the_shared_fixture(string $text, bool $expected): void
    {
        $this->assertSame($expected, SynthesisAbstention::phrase($text));
    }

    /**
     * @param  array<string,mixed>  $trace
     * @return array<string,mixed>
     */
    private function score(string $answer, array $trace, string $outcome = 'answer'): array
    {
        $m = new ReflectionMethod(AnswerGate::class, 'scoreCase');
        $result = ['outcome' => $outcome, 'answer' => $answer, 'citations' => []];

        return $m->invoke($this->app->make(AnswerGate::class), ['id' => 'x', 'expect' => []], 'agent', $result, $trace + ['floor_decision' => ['path' => 'prose', 'outcome' => $outcome, 'authority_used' => ['official_convenio']]], null);
    }

    public function test_the_gate_classifies_an_abstention_as_abstain_never_answer(): void
    {
        // no flag was ever recorded (older trace / sub-flag off): the opening-sentence phrase is the fallback
        $row = $this->score(self::LP14, []);
        $this->assertSame('answer', $row['outcome'], 'the raw outcome is kept');
        $this->assertSame('abstain', $row['outcome_class']);
        $this->assertSame('phrase', $row['abstained_by']);

        // the structured flag, recorded on the trace
        $row = $this->score('Texto cualquiera.', ['floor_decision' => ['path' => 'prose', 'outcome' => 'answer', 'synthesis_abstained' => ['flag' => true, 'by' => 'model_flag']]]);
        $this->assertSame('abstain', $row['outcome_class']);
        $this->assertSame('flag', $row['abstained_by']);

        // a real answer, and an answer that merely ENDS with a caveat, stay answers
        $this->assertSame('answer', $this->score('La excedencia voluntaria es posible [Fuente 1].', [])['outcome_class']);
        $this->assertSame('answer', $this->score('La jornada es la del convenio [Fuente 1]. No dispongo de información suficiente sobre el cómputo anual.', [])['outcome_class']);

        // a turn that ASKED for the flag and got abstained=false is not overridden by the phrase
        $asked = ['synthesis' => ['abstention' => ['abstained' => false, 'by' => 'model_flag']]];
        $this->assertSame('answer', $this->score(self::LP14, $asked)['outcome_class']);

        // an escalation is never reclassified
        $this->assertSame('escalate', $this->score(self::LP14, [], 'escalate')['outcome_class']);
    }

    public function test_the_gate_summary_counts_an_abstention_apart_from_answers(): void
    {
        $m = new ReflectionMethod(AnswerGate::class, 'summarize');
        $rows = [
            array_merge($this->score('La excedencia voluntaria es posible [Fuente 1].', []), ['class' => 'c', 'engine' => 'agent']),
            array_merge($this->score(self::LP14, []), ['class' => 'c', 'engine' => 'agent']),
        ];
        $summary = $m->invoke($this->app->make(AnswerGate::class), $rows, ['agent']);
        $e = $summary['engines']['agent'];

        $this->assertSame(1, $e['answers']);
        $this->assertSame(['answer' => 1, 'abstain' => 1], $e['outcomes']);
        $this->assertSame(1, $e['by_class']['c']['abstentions']);
        $this->assertSame(1, $e['by_class']['c']['answers']);
    }
}

class SynthAbstentionFake extends ExtractionClient
{
    /** @var array<string,mixed> */
    public array $retrieveResponse = ['chunks' => [], 'eligible_total' => 0];

    /** @var array<string,mixed> */
    public array $synthesiseResponse = [];

    /** @var array<string,mixed> */
    public array $groundResponse = ['grounded' => true, 'claims' => [], 'ungrounded' => [], 'trace_fragment' => []];

    /** @var array<string,mixed> */
    public array $lastSynthConfig = [];

    public function route(string $question, string $decryptedKey, array $providerConfig): array
    {
        return ['label' => 'prose', 'confidence' => 1.0, 'subqueries' => [], 'reason' => 'llm', 'trace_fragment' => []];
    }

    public function retrieve(array $params): array
    {
        return $this->retrieveResponse;
    }

    public function synthesise(string $question, array $chunks, string $decryptedKey, array $providerConfig): array
    {
        $this->lastSynthConfig = $providerConfig;

        return $this->synthesiseResponse;
    }

    public function ground(string $question, string $answer, array $chunks, string $decryptedKey, array $providerConfig): array
    {
        return $this->groundResponse;
    }
}
