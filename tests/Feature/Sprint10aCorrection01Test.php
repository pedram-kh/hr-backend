<?php

namespace Tests\Feature;

use App\Models\AnswerModelSetting;
use App\Models\ChatSession;
use App\Models\Convenio;
use App\Models\ConvenioJobCategory;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Employee;
use App\Models\Sector;
use App\Models\Territory;
use App\Services\ChatService;
use App\Services\ConversationPresenter;
use App\Services\ExtractionClient;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Sprint 10a — Correction-01. Source: CP-4 eyes-on, step 1 (employee view,
 * `test-fullgap@`). Three employee-presentation findings:
 *
 *   E1 — `[Fuente N]` markers rendered literally in the employee answer text.
 *   E2 — the fallback caveat exposed internal system state and raw markdown.
 *   E3 — the employee chat sent the full trace object + citation excerpts
 *        (admin material) to the employee-facing endpoints.
 *
 * These tests exist to prove the RESPONSE BOUNDARY changed, not the decision
 * underneath it — every assertion here is about what an endpoint returns or a
 * presenter shapes, never about `ChatService::handleMessage()`'s own contract
 * (that is `Sprint10aInvariantTest`'s job, and it calls `handleMessage()`
 * directly, bypassing this correction's controller-level reshaping entirely —
 * so T1-T12 there are unaffected by this file's changes, and this file proves
 * that independently by hitting the real HTTP routes).
 *
 * E3 pre-existence finding (recorded here and in correction-01.md/review.md):
 * `ChatController::message()` has returned `handleMessage()`'s array —
 * including `trace` and full citation excerpts — AS-IS since Sprint 2b-1
 * (`git show b2114a4` — the file's original commit already does
 * `return response()->json($result)` on the raw service result), and
 * `ChatScreen.tsx` has rendered `<CitationList>`/`<TracePanel>` on that same
 * data since that same commit. `ConversationPresenter` (Sprint 4) inherited
 * the identical shape for the session-hydration endpoint. So the CHANNEL is
 * pre-existing, not introduced this sprint — but Sprint 10a's own additions to
 * `MessageTrace` (`prose_gap`, `floor_decision.fallback`) put NEW, more
 * sensitive content through that pre-existing channel: on the `expired_only`
 * escalation path, `trace.prose_gap` and `trace.floor_decision.note` narrate
 * the exact internal classification and cite ADR-0032 by number — content
 * `Sprint10aInvariantTest::test_t9b_*` assumed was employee-invisible because
 * it checked only `result['answer']`, never `result['trace']`. E3's fix (stop
 * sending `trace` on employee endpoints) closes that gap as a side effect;
 * `test_t9b_*` itself is unchanged (still correct, just no longer the only
 * thing preventing this specific leak).
 */
class Sprint10aCorrection01Test extends TestCase
{
    use RefreshDatabase;

    private Convenio $convenio;

    private Employee $employee;

    private int $docId;

    private int $chunkId;

    private Correction01FakeExtractionClient $fake;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->buildWorld();

        $this->fake = new Correction01FakeExtractionClient;
        $this->app->instance(ExtractionClient::class, $this->fake);

        $setting = new AnswerModelSetting(['provider' => 'claude']);
        $setting->id = 1;
        $setting->save();
        $setting->setKey('sk-test-key-abcd', null);
    }

    private function buildWorld(): void
    {
        $territory = Territory::create(['code' => '01', 'name' => 'Álava', 'level' => 'provincial', 'aliases' => []]);
        $sector = Sector::create(['name' => 'Sector', 'aliases' => []]);
        $this->convenio = Convenio::create([
            'numero' => '01TEST0002', 'name' => 'Test Convenio Correction-01',
            'territory_id' => $territory->id, 'sector_id' => $sector->id,
        ]);
        $jobCategory = ConvenioJobCategory::create([
            'convenio_id' => $this->convenio->id, 'name' => 'Técnico/a', 'group_code' => null,
        ]);
        $this->employee = Employee::create([
            'email' => 'correction01@example.com', 'full_name' => 'Correction Worker',
            'convenio_id' => $this->convenio->id, 'job_category_id' => $jobCategory->id,
            'territory_id' => $territory->id, 'employment_type' => 'full_time', 'status' => 'active',
        ]);

        $type = DocumentType::firstOrCreate(['code' => 'convenio_text'], ['name' => 'Convenio (texto)']);
        $doc = Document::create([
            'title' => 'Convenio Test Correction-01', 'storage_path' => 'test/doc-c01.pdf',
            'convenio_id' => $this->convenio->id, 'document_type_id' => $type->id,
            'retrieval_status' => 'active', 'authority_level' => 'official_convenio',
            'language' => 'es', 'tagging_status' => 'verified',
        ]);
        $this->docId = $doc->id;

        $this->chunkId = (int) DB::table('document_chunks')->insertGetId([
            'document_id' => $this->docId,
            'chunk_index' => 0,
            'page_from' => 1,
            'page_to' => 1,
            'content' => 'Las vacaciones anuales son de 30 días naturales según el convenio.',
            'token_count' => 12,
            'convenio_id' => $this->convenio->id,
            'retrieval_status' => 'active',
            'authority_level' => 'official_convenio',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** Configure the fake to ANSWER a prose vacaciones turn (grounded + cited). */
    private function primeAnswerableProseTurn(): void
    {
        $chunk = [
            'id' => $this->chunkId, 'document_id' => $this->docId, 'page_from' => 1, 'page_to' => 1,
            'content' => 'Las vacaciones anuales son de 30 días naturales según el convenio.',
            'score' => 0.9, 'authority_level' => 'official_convenio',
        ];
        $this->fake->routeLabel = 'prose';
        $this->fake->retrieveResponse = ['chunks' => [$chunk], 'eligible_total' => 1];
        $this->fake->synthesiseResponse = [
            'answer' => 'Tus vacaciones son de 30 días [Fuente 1].',
            'citations' => [['chunk_id' => $this->chunkId, 'document_id' => $this->docId, 'page_from' => 1, 'page_to' => 1, 'authority_level' => 'official_convenio']],
            'grounding_signal' => ['grounded' => true, 'citation_count' => 1, 'top_chunk_score' => 0.9],
            'confidence' => 0.9, 'authority_used' => ['official_convenio'], 'trace_fragment' => [],
        ];
        $this->fake->groundResponse = ['grounded' => true, 'claims' => [], 'ungrounded' => [], 'trace_fragment' => []];
    }

    private function askAsEmployee(string $question): array
    {
        $token = $this->employee->createToken('emp')->plainTextToken;

        return $this->postJson('/chat/message', ['question' => $question], [
            'Authorization' => 'Bearer '.$token, 'Accept' => 'application/json',
        ])->json();
    }

    private function sessionAsEmployee(): array
    {
        $token = $this->employee->createToken('emp')->plainTextToken;

        return $this->getJson('/chat/session', [
            'Authorization' => 'Bearer '.$token, 'Accept' => 'application/json',
        ])->json();
    }

    // -- E3: the live turn ----------------------------------------------------

    public function test_e3_the_employee_live_turn_response_has_no_trace_key(): void
    {
        $this->primeAnswerableProseTurn();
        $result = $this->askAsEmployee('¿Cuántos días de vacaciones tengo?');

        $this->assertSame('answer', $result['outcome']);
        $this->assertArrayNotHasKey(
            'trace',
            $result,
            'the employee live-turn response must not carry the trace object at all (E3) — absent, not null'
        );
        $this->assertSame(
            [],
            $result['citations'],
            'citation EXCERPTS are admin material — the employee gets source_labels instead'
        );
        $this->assertSame(['Convenio Test Correction-01'], $result['source_labels']);

        // E1's fix is a FRONTEND display transform only — the backend/API
        // answer text still carries the raw marker, exactly as before, so
        // Check B keeps parsing the stored/returned content unchanged.
        $this->assertStringContainsString('[Fuente 1]', $result['answer']);
    }

    public function test_e3_the_stored_row_and_admin_tables_are_untouched_by_the_reshaping(): void
    {
        $this->primeAnswerableProseTurn();
        $result = $this->askAsEmployee('¿Cuántos días de vacaciones tengo?');

        $persisted = DB::table('chat_messages')->where('id', $result['message_id'])->value('content');
        $this->assertStringContainsString('[Fuente 1]', $persisted);
        $this->assertDatabaseHas('message_citations', [
            'message_id' => $result['message_id'], 'chunk_id' => $this->chunkId,
        ]);
        $this->assertDatabaseHas('message_traces', ['message_id' => $result['message_id']]);
    }

    // -- E3: session hydration -------------------------------------------------

    public function test_e3_the_employee_session_hydration_has_no_trace_key_either(): void
    {
        $this->primeAnswerableProseTurn();
        $this->askAsEmployee('¿Cuántos días de vacaciones tengo?');

        $session = $this->sessionAsEmployee();
        $assistantMessages = array_values(array_filter(
            $session['messages'],
            fn ($m) => $m['role'] === 'assistant'
        ));

        $this->assertCount(1, $assistantMessages);
        $this->assertArrayNotHasKey('trace', $assistantMessages[0]);
        $this->assertSame([], $assistantMessages[0]['citations']);
        $this->assertSame(['Convenio Test Correction-01'], $assistantMessages[0]['source_labels']);
    }

    // -- E3: the admin equivalent is unaffected --------------------------------

    public function test_e3_the_admin_presenter_still_gets_full_trace_and_citation_excerpts(): void
    {
        $this->primeAnswerableProseTurn();
        $this->askAsEmployee('¿Cuántos días de vacaciones tengo?');

        $session = ChatSession::where('employee_id', $this->employee->id)->first();
        $adminRows = app(ConversationPresenter::class)->present($session, ConversationPresenter::AUDIENCE_ADMIN);
        $assistantRow = collect($adminRows)->firstWhere('role', 'assistant');

        $this->assertNotNull($assistantRow);
        $this->assertArrayHasKey('trace', $assistantRow);
        $this->assertNotNull($assistantRow['trace']);
        $this->assertNotEmpty($assistantRow['citations']);
        $this->assertNotEmpty($assistantRow['citations'][0]['snippet'], 'admin still sees the FUENTES excerpt');
        $this->assertArrayNotHasKey('source_labels', $assistantRow, 'source_labels is an employee-only field');
    }

    // -- E2: the new caveat wording ---------------------------------------------

    public function test_e2_the_caveat_constant_is_plain_text_with_no_markdown_or_system_state_language(): void
    {
        $caveat = ChatService::FALLBACK_CAVEAT;

        $this->assertStringNotContainsString('**', $caveat);
        $this->assertStringNotContainsString('---', $caveat);
        $this->assertStringNotContainsString('todavía no está cargado', $caveat);
        $this->assertStringNotContainsString('en el sistema', $caveat);
        $this->assertStringContainsString('mínimos legales', $caveat);
        $this->assertStringContainsString('convenio colectivo', $caveat);
        $this->assertStringContainsString('Recursos Humanos', $caveat);

        $this->assertSame(
            "\n\nEsta respuesta se basa en el Estatuto de los Trabajadores, que establece los "
            .'mínimos legales para cualquier persona trabajadora. Tu convenio colectivo puede '
            .'mejorar estas condiciones (nunca empeorarlas). Para confirmar lo que se aplica en '
            .'tu caso concreto, consulta con Recursos Humanos.',
            $caveat,
        );
    }
}

/**
 * Recording fake for hr-ai (no real provider call) — a Correction-01-scoped
 * copy of `Sprint6GuardrailInvariantTest`'s `FakeExtractionClient`, named
 * distinctly so both test files can coexist in one PHPUnit run without a
 * class-name collision (each is declared inline in its own test file, not on
 * its own PSR-4 path).
 */
class Correction01FakeExtractionClient extends ExtractionClient
{
    public string $routeLabel = 'prose';

    /** @var array<string,mixed> */
    public array $retrieveResponse = ['chunks' => [], 'eligible_total' => 0];

    /** @var array<string,mixed> */
    public array $synthesiseResponse = [];

    /** @var array<string,mixed> */
    public array $groundResponse = ['grounded' => true, 'claims' => [], 'ungrounded' => [], 'trace_fragment' => []];

    public function route(string $question, string $decryptedKey, array $providerConfig): array
    {
        return ['label' => $this->routeLabel, 'confidence' => 1.0, 'subqueries' => [], 'reason' => 'llm', 'trace_fragment' => []];
    }

    public function retrieve(array $params): array
    {
        return $this->retrieveResponse;
    }

    public function synthesise(string $question, array $chunks, string $decryptedKey, array $providerConfig): array
    {
        return $this->synthesiseResponse;
    }

    public function ground(string $question, string $answer, array $chunks, string $decryptedKey, array $providerConfig): array
    {
        return $this->groundResponse;
    }
}
