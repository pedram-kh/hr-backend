<?php

namespace Tests\Feature;

use App\Models\AnswerModelSetting;
use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Models\Convenio;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Employee;
use App\Models\Sector;
use App\Models\Territory;
use App\Services\Agent\AgentChatService;
use App\Services\Agent\PlannerClient;
use App\Services\ChatService;
use App\Services\ExtractionClient;
use Database\Seeders\DocumentTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Sprint 13, build step 5 (plan.md §B.3.3/§B.3.4) — proves
 * `Tools\ConvenioSearchTool` + `Tools\NationalLawTool` +
 * `Rules\ProseCheckAPostCallRule` + `Rules\NationalLawPrecedenceRule`
 * reproduce `ProsePath::handle()`'s classic call sites exactly (cases
 * 17/19/20/21/22 of `Sprint13GoldenTraceTest`), plus the one shape those
 * golden cases can't cover because it isn't terminal: an R16 Check-A miss
 * being re-surfaced VERBATIM by the finisher (`AgentChatService::finish()`)
 * rather than forced immediately — and the `national_law` rewrite-to-
 * `convenio_search` rule on a `covered` convenio (§B.3.4's verdict table).
 */
class Sprint13ConvenioSearchWrapperTest extends TestCase
{
    use RefreshDatabase;

    private Territory $territory;

    private Sector $sector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DocumentTypeSeeder::class);
        $this->territory = Territory::create(['code' => '31', 'name' => 'Navarra', 'level' => 'provincial', 'aliases' => []]);
        $this->sector = Sector::create(['name' => 'Hostelería', 'aliases' => []]);
    }

    private function convenio(string $numero): Convenio
    {
        return Convenio::create([
            'numero' => '13CSW-'.$numero, 'name' => 'Convenio '.$numero,
            'territory_id' => $this->territory->id, 'sector_id' => $this->sector->id,
        ]);
    }

    private function employee(Convenio $convenio): Employee
    {
        return Employee::create([
            'email' => 'csw-'.$convenio->numero.'@example.com', 'full_name' => 'Convenio Search Wrapper Test',
            'convenio_id' => $convenio->id, 'territory_id' => $this->territory->id,
            'employment_type' => 'full_time', 'status' => 'active',
        ]);
    }

    private function doc(?Convenio $convenio, string $title, string $authority, string $typeCode = 'convenio_text'): Document
    {
        return Document::create([
            'uuid' => (string) Str::uuid(),
            'title' => $title,
            'storage_path' => 'fake/'.Str::uuid(),
            'convenio_id' => $convenio?->id,
            'document_type_id' => DocumentType::where('code', $typeCode)->value('id'),
            'authority_level' => $authority,
            'retrieval_status' => 'active',
            'language' => 'es',
            'tagging_status' => 'verified',
        ]);
    }

    private function configureAnswerModel(): void
    {
        AnswerModelSetting::query()->delete();
        $s = new AnswerModelSetting(['provider' => 'claude']);
        $s->id = 1;
        $s->setKey('test-key-1234');
    }

    /** @param  array<string, array<string,mixed>|callable>  $script */
    private function bindAi(array $script): void
    {
        $fake = new class($script) extends ExtractionClient
        {
            public function __construct(private array $script) {}

            private function resolve(string $key, array $args): array
            {
                if (! array_key_exists($key, $this->script)) {
                    throw new \RuntimeException("convenio-search wrapper fixture: unexpected /{$key} call");
                }
                $v = $this->script[$key];

                return is_callable($v) ? $v(...$args) : $v;
            }

            public function route(string $question, string $decryptedKey, array $providerConfig): array
            {
                return $this->resolve('route', [$question]);
            }

            public function retrieve(array $params): array
            {
                return $this->resolve('retrieve', [$params]);
            }

            public function synthesise(string $question, array $chunks, string $decryptedKey, array $providerConfig): array
            {
                return $this->resolve('synthesise', [$question, $chunks]);
            }

            public function ground(string $question, string $answer, array $chunks, string $decryptedKey, array $providerConfig): array
            {
                return $this->resolve('ground', [$question, $answer, $chunks]);
            }
        };

        $this->app->instance(ExtractionClient::class, $fake);
    }

    private function followUpSession(Employee $employee): ChatSession
    {
        $session = ChatSession::create(['employee_id' => $employee->id, 'started_at' => now(), 'last_activity_at' => now()]);
        ChatMessage::create(['session_id' => $session->id, 'role' => 'user', 'content' => 'Hola, tengo una duda.']);
        ChatMessage::create(['session_id' => $session->id, 'role' => 'assistant', 'content' => 'Claro, dime.']);

        return $session;
    }

    /** A planner that calls $tool once, then `finalize`. */
    private function singleToolThenFinalize(string $tool): PlannerClient
    {
        return new class($tool) implements PlannerClient
        {
            private int $round = 0;

            public function __construct(private string $tool) {}

            public function plan(string $question, array $scopeSummary, array $window, array $toolDefinitions, array $priorSteps): array
            {
                $this->round++;
                if ($this->round === 1) {
                    return ['calls' => [['id' => 't1', 'tool' => $this->tool, 'input' => []]], 'stop_reason' => 'tool_use', 'model' => null, 'request_id' => null, 'prompt_version' => null, 'tokens' => [], 'ms' => 0];
                }

                return ['calls' => [['id' => 't2', 'tool' => 'finalize', 'input' => ['use' => ['t1']]]], 'stop_reason' => 'tool_use', 'model' => null, 'request_id' => null, 'prompt_version' => null, 'tokens' => [], 'ms' => 0];
            }
        };
    }

    private function comparableFields(array $response): array
    {
        return [
            'outcome' => $response['outcome'],
            'escalated' => $response['escalated'],
            'escalation_reason' => $response['escalation_reason'],
            'answer' => $response['answer'],
            'citations' => $response['citations'],
            'floor_decision' => $response['trace']['floor_decision'] ?? null,
            'retrieval' => $response['trace']['retrieval'] ?? null,
            'synthesis' => $response['trace']['synthesis'] ?? null,
        ];
    }

    private function runViaAgentWrapper(Employee $employee, string $question, string $tool): array
    {
        $this->app->bind(PlannerClient::class, fn () => $this->singleToolThenFinalize($tool));
        $session = $this->followUpSession($employee);

        return app(AgentChatService::class)->handle($employee, $question, $session->uuid);
    }

    private function runViaClassic(Employee $employee, string $question): array
    {
        return app(ChatService::class)->handleMessage($employee, $question);
    }

    public function test_estatuto_fallback_gap_expired_only_matches_classic(): void
    {
        $convenio = $this->convenio('expired');
        $this->doc($convenio, 'Convenio recién subido', 'official_convenio'); // active doc, ZERO chunks
        $employee = $this->employee($convenio);
        $this->bindAi([]);

        $classic = $this->runViaClassic($employee, '¿Cuántos días de vacaciones tengo?');
        $agent = $this->runViaAgentWrapper($employee, '¿Cuántos días de vacaciones tengo?', 'convenio_search');

        $this->assertSame('escalate', $classic['outcome']);
        $this->assertSame('estatuto_fallback_gap', $classic['escalation_reason']);
        $this->assertSame($this->comparableFields($classic), $this->comparableFields($agent));
    }

    public function test_estatuto_fallback_answer_never_ingested_matches_classic(): void
    {
        $convenio = $this->convenio('never'); // nothing at all → never_ingested
        $employee = $this->employee($convenio);
        $this->configureAnswerModel();
        $estatutoDoc = $this->doc(null, 'Estatuto de los Trabajadores', 'national_law', 'national_law');
        $chunkId = DB::table('document_chunks')->insertGetId([
            'document_id' => $estatutoDoc->id, 'chunk_index' => 0,
            'page_from' => 38, 'page_to' => 38, 'content' => 'El periodo de vacaciones anuales será de 30 días naturales.',
            'token_count' => 10, 'convenio_id' => null, 'retrieval_status' => 'active',
            'authority_level' => 'national_law', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->bindAi([
            'route' => ['label' => 'prose', 'confidence' => 0.9, 'subqueries' => [], 'reason' => null, 'trace_fragment' => []],
            'retrieve' => ['chunks' => [[
                'id' => $chunkId, 'document_id' => $estatutoDoc->id, 'page_from' => 38, 'page_to' => 38,
                'content' => 'El periodo de vacaciones anuales será de 30 días naturales.',
                'score' => 0.85, 'authority_level' => 'national_law',
            ]], 'eligible_total' => 1],
            'synthesise' => [
                'answer' => 'Según el Estatuto de los Trabajadores, las vacaciones son de 30 días naturales. [Fuente 1]',
                'citations' => [['chunk_id' => $chunkId, 'document_id' => $estatutoDoc->id, 'page_from' => 38, 'page_to' => 38, 'authority_level' => 'national_law']],
                'grounding_signal' => ['grounded' => true, 'citation_count' => 1, 'top_chunk_score' => 0.85],
                'confidence' => 0.9,
                'authority_used' => ['national_law'],
                'trace_fragment' => [],
            ],
            'ground' => ['grounded' => true, 'claims' => [['claim' => '30 días naturales', 'grounded' => true, 'supporting_source' => $chunkId]], 'ungrounded' => [], 'trace_fragment' => []],
        ]);

        $classic = $this->runViaClassic($employee, '¿Cuántos días de vacaciones tengo?');
        $agent = $this->runViaAgentWrapper($employee, '¿Cuántos días de vacaciones tengo?', 'convenio_search');

        $this->assertSame('answer', $classic['outcome']);
        $this->assertSame('estatuto_gap', $classic['trace']['floor_decision']['fallback']);
        $this->assertSame($this->comparableFields($classic), $this->comparableFields($agent));
    }

    public function test_check_b_citations_fail_matches_classic(): void
    {
        $convenio = $this->convenio('checkb');
        $doc = $this->doc($convenio, 'Convenio (texto)', 'official_convenio');
        $chunkId = DB::table('document_chunks')->insertGetId([
            'document_id' => $doc->id, 'chunk_index' => 0,
            'page_from' => 5, 'page_to' => 5, 'content' => 'La excedencia voluntaria podrá solicitarse tras un año de antigüedad.',
            'token_count' => 10, 'convenio_id' => $convenio->id, 'retrieval_status' => 'active',
            'authority_level' => 'official_convenio', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $employee = $this->employee($convenio);
        $this->configureAnswerModel();
        $this->bindAi([
            'route' => ['label' => 'prose', 'confidence' => 0.9, 'subqueries' => [], 'reason' => null, 'trace_fragment' => []],
            'retrieve' => ['chunks' => [[
                'id' => $chunkId, 'document_id' => $doc->id, 'page_from' => 5, 'page_to' => 5,
                'content' => 'La excedencia voluntaria podrá solicitarse tras un año de antigüedad.',
                'score' => 0.80, 'authority_level' => 'official_convenio',
            ]], 'eligible_total' => 1],
            'synthesise' => [
                'answer' => 'La excedencia se concede tras un año. [Fuente 1]',
                'citations' => [['chunk_id' => 999999, 'document_id' => $doc->id, 'page_from' => 5, 'page_to' => 5, 'authority_level' => 'official_convenio']],
                'grounding_signal' => [],
                'confidence' => 0.9,
                'authority_used' => ['official_convenio'],
                'trace_fragment' => [],
            ],
        ]);

        $classic = $this->runViaClassic($employee, '¿Cuánto tiempo de excedencia me corresponde?');
        $agent = $this->runViaAgentWrapper($employee, '¿Cuánto tiempo de excedencia me corresponde?', 'convenio_search');

        $this->assertSame('escalate', $classic['outcome']);
        $this->assertSame('low_confidence', $classic['escalation_reason']);
        $this->assertFalse($classic['trace']['floor_decision']['check_b_citations']);
        $this->assertSame($this->comparableFields($classic), $this->comparableFields($agent));
    }

    /**
     * The one shape with no golden-trace equivalent because it is NOT
     * terminal: an R16 Check-A miss. Proved here in two parts — (1) classic
     * escalates `low_confidence` with `check_a_retrieval = false`; (2) the
     * agent wrapper, given the SAME planner-forced sequence
     * (`convenio_search` then `finalize` — no OTHER tool tried), reaches the
     * IDENTICAL escalation via `AgentChatService::finish()`'s re-surfacing
     * path, not a forced verdict at `post_call:convenio_search` (asserted by
     * checking the `tool_call` step for `convenio_search` — proving it did
     * NOT force — followed by a `finalize` step).
     */
    public function test_check_a_fail_is_not_forced_and_the_finisher_reuses_it_verbatim(): void
    {
        $convenio = $this->convenio('checka');
        $doc = $this->doc($convenio, 'Convenio (texto)', 'official_convenio');
        DB::table('document_chunks')->insert([
            'document_id' => $doc->id, 'chunk_index' => 0,
            'page_from' => 1, 'page_to' => 1, 'content' => 'texto irrelevante de otro tema',
            'token_count' => 6, 'convenio_id' => $convenio->id, 'retrieval_status' => 'active',
            'authority_level' => 'official_convenio', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $employee = $this->employee($convenio);
        $this->bindAi(['retrieve' => ['chunks' => [], 'eligible_total' => 0]]);

        $classic = $this->runViaClassic($employee, '¿Cuántos días de excedencia tengo?');
        $agent = $this->runViaAgentWrapper($employee, '¿Cuántos días de excedencia tengo?', 'convenio_search');

        $this->assertSame('escalate', $classic['outcome']);
        $this->assertSame('low_confidence', $classic['escalation_reason']);
        $this->assertFalse($classic['trace']['floor_decision']['check_a_retrieval']);
        $this->assertSame($this->comparableFields($classic), $this->comparableFields($agent));

        $steps = $agent['trace']['agent']['steps'];
        $toolCallStep = collect($steps)->firstWhere('tool', 'convenio_search');
        $this->assertNotNull($toolCallStep, 'convenio_search must have run and left a plain tool_call step (not forced)');
        $this->assertSame('tool_call', $toolCallStep['type']);
        $this->assertSame('no_material', $toolCallStep['status']);
        $finalizeStep = collect($steps)->firstWhere('type', 'finalize');
        $this->assertNotNull($finalizeStep, 'the finisher must have actually run to re-surface the escalation');
    }

    public function test_national_law_rewrites_to_convenio_search_on_covered_convenio(): void
    {
        $convenio = $this->convenio('covered');
        $doc = $this->doc($convenio, 'Convenio (texto)', 'official_convenio');
        $chunkId = DB::table('document_chunks')->insertGetId([
            'document_id' => $doc->id, 'chunk_index' => 0,
            'page_from' => 3, 'page_to' => 3, 'content' => 'El periodo de vacaciones será de 37 días naturales.',
            'token_count' => 10, 'convenio_id' => $convenio->id, 'retrieval_status' => 'active',
            'authority_level' => 'official_convenio', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $employee = $this->employee($convenio);
        $this->configureAnswerModel();
        $this->bindAi([
            'retrieve' => fn (array $p) => ($p['convenio_id'] ?? null) === $convenio->id
                ? ['chunks' => [[
                    'id' => $chunkId, 'document_id' => $doc->id, 'page_from' => 3, 'page_to' => 3,
                    'content' => 'El periodo de vacaciones será de 37 días naturales.',
                    'score' => 0.9, 'authority_level' => 'official_convenio',
                ]], 'eligible_total' => 1]
                : ['chunks' => [], 'eligible_total' => 0],
            'synthesise' => [
                'answer' => 'Tienes 37 días de vacaciones. [Fuente 1]',
                'citations' => [['chunk_id' => $chunkId, 'document_id' => $doc->id, 'page_from' => 3, 'page_to' => 3, 'authority_level' => 'official_convenio']],
                'grounding_signal' => [],
                'confidence' => 0.9,
                'authority_used' => ['official_convenio'],
                'trace_fragment' => [],
            ],
            'ground' => ['grounded' => true, 'claims' => [['claim' => '37 días naturales', 'grounded' => true, 'supporting_source' => $chunkId]], 'ungrounded' => [], 'trace_fragment' => []],
        ]);

        $agent = $this->runViaAgentWrapper($employee, '¿Cuántos días de vacaciones tengo?', 'national_law');

        $this->assertSame('answer', $agent['outcome']);
        $this->assertStringContainsString('37', $agent['answer']);

        // The answer being the convenio's 37 days (not the Estatuto's 30, or
        // an escalation) already proves the rewritten call ran
        // `convenio_search`'s real union+re-rank — a forced `finish` result
        // (see `runToolCall()`) records NO `tool_call` step at all, so the
        // rewrite itself is what's directly observable in `steps`.
        $steps = $agent['trace']['agent']['steps'];
        $rewriteStep = collect($steps)->first(fn ($s) => ($s['type'] ?? null) === 'rule_verdict' && $s['rule'] === 'national_law_precedence');
        $this->assertNotNull($rewriteStep, 'the national_law_precedence rule must have fired');
        $this->assertSame('rewrite', $rewriteStep['verdict']);
    }
}
