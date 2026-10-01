<?php

namespace Tests\Feature;

use App\Models\AnswerModelSetting;
use App\Models\Convenio;
use App\Models\ConvenioJobCategory;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Employee;
use App\Models\EscalationCard;
use App\Models\GuardrailBlockedTopic;
use App\Models\ReferenceFact;
use App\Models\SalaryTable;
use App\Models\SalaryTableRow;
use App\Models\Sector;
use App\Models\Territory;
use App\Models\Topic;
use App\Services\ChatService;
use App\Services\ExtractionClient;
use App\Services\GuardrailPolicy;
use Database\Seeders\DocumentTypeSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Sprint 13, build step 0 (plan.md §E.15) — the GOLDEN-TRACE baseline for the
 * §B.1 extraction refactor (step 1).
 *
 * One scripted fixture per path shape (22 in the step-0 list — the plan's own
 * enumeration double-counts as "21" in prose but lists 22 distinct items;
 * every one is here): sensitive, legal/medical, other-employee, admin block,
 * explicit request, off-domain, cross-path, SMI, salary answer, needs_category,
 * salary gap, fact P1, fact P2, fact conflict, fact gap, aggregation,
 * expired_only, fallback answer, Check A fail, Check B fail, figure-guard,
 * entailment fail.
 *
 * Captured GREEN against UNMODIFIED `ChatService` (the pre-step-1 baseline —
 * mirrors the `Sprint7cAdditivityRegressionTest` discipline, extended to every
 * path shape instead of just prose+salary). Each case's full response payload
 * (outcome, escalation_reason, answer, citations, categories, authority_used,
 * trace — EXCLUDING the non-deterministic session_uuid/message_id/
 * escalation_uuid) is recorded to a checked-in JSON fixture under
 * `tests/Fixtures/golden-traces/`. After the step-1 extraction, this same test
 * file re-runs UNCHANGED; every fixture must still compare byte-for-byte
 * (structurally — `assertJsonStringEqualsJsonString`, key-order-independent,
 * value-exact) or the refactor claim does not hold — CP-0 (plan.md §E.15).
 *
 * On a machine with no fixture yet, `assertGoldenTrace()` WRITES the file and
 * FAILS the test on purpose (two-run discipline: the first run only records
 * the baseline; a human/CI must see it turn green on a second run before it
 * is trusted — the same reasoning as a snapshot-test "please review and
 * re-run", made explicit rather than silently auto-accepting a snapshot).
 */
class Sprint13GoldenTraceTest extends TestCase
{
    use RefreshDatabase;

    private Territory $territory;

    private Sector $sector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(DocumentTypeSeeder::class);

        $this->territory = Territory::create(['code' => '31', 'name' => 'Navarra', 'level' => 'provincial', 'aliases' => []]);
        $this->sector = Sector::create(['name' => 'Hostelería', 'aliases' => []]);
    }

    // =========================================================================
    // 1. sensitive_topic (R01) — GuardrailService baseline, pre-model, no AI call.
    // =========================================================================
    public function test_01_sensitive_topic_baseline(): void
    {
        $employee = $this->employee($this->convenio('13-01'));
        $this->bindAi([]); // exploding — no hr-ai call may happen

        $result = app(ChatService::class)->handleMessage($employee, 'Estoy siendo víctima de acoso en el trabajo, ¿qué hago?');

        $this->assertSame('escalate', $result['outcome']);
        $this->assertSame('sensitive_topic', $result['escalation_reason']);
        $this->assertGoldenTrace('01_sensitive_topic', $result);
    }

    // =========================================================================
    // 2. off_domain / legal_medical (R02) — pre-model, no AI call.
    // =========================================================================
    public function test_02_legal_medical(): void
    {
        $employee = $this->employee($this->convenio('13-02'));
        $this->bindAi([]);

        $result = app(ChatService::class)->handleMessage($employee, 'Necesito consejo legal para presentar una demanda judicial.');

        $this->assertSame('escalate', $result['outcome']);
        $this->assertSame('off_domain', $result['escalation_reason']);
        $this->assertGoldenTrace('02_legal_medical', $result);
    }

    // =========================================================================
    // 3. off_domain / other_employee_data (R03) — pre-model, no AI call.
    // =========================================================================
    public function test_03_other_employee_data(): void
    {
        $employee = $this->employee($this->convenio('13-03'));
        $this->bindAi([]);

        $result = app(ChatService::class)->handleMessage($employee, '¿Cuánto gana Pedro García?');

        $this->assertSame('escalate', $result['outcome']);
        $this->assertSame('off_domain', $result['escalation_reason']);
        $this->assertGoldenTrace('03_other_employee_data', $result);
    }

    // =========================================================================
    // 4. admin blocked topic (R04) — GuardrailPolicy additive layer, no AI call.
    // =========================================================================
    public function test_04_admin_blocked_topic(): void
    {
        $employee = $this->employee($this->convenio('13-04'));
        GuardrailBlockedTopic::create(['pattern' => 'gimnasio', 'kind' => GuardrailBlockedTopic::KIND_OFF_DOMAIN, 'enabled' => true]);
        GuardrailPolicy::flush();
        $this->bindAi([]);

        $result = app(ChatService::class)->handleMessage($employee, '¿La empresa paga el gimnasio?');

        // Slice 13e (ADR-0039) — the ONE disclosed re-record in the golden set: the admin's own off-domain pattern is now a
        // DECLINE (no card, no escalation reason). Every other fixture is byte-identical. The pre-13e content lives on in
        // `04_admin_blocked_topic_flag_off` below, which is what the kill switch must still produce.
        $this->assertSame('decline', $result['outcome']);
        $this->assertNull($result['escalation_reason']);
        $this->assertFalse($result['escalated']);
        $this->assertNull($result['escalation_uuid']);
        $this->assertGoldenTrace('04_admin_blocked_topic', $result);
    }

    /** Slice 13e kill switch: `HR_DECLINE_ENABLED=false` restores the pre-13e turn byte for byte. */
    public function test_04_admin_blocked_topic_flag_off(): void
    {
        config(['hr.decline.enabled' => false]);
        $employee = $this->employee($this->convenio('13-04'));
        GuardrailBlockedTopic::create(['pattern' => 'gimnasio', 'kind' => GuardrailBlockedTopic::KIND_OFF_DOMAIN, 'enabled' => true]);
        GuardrailPolicy::flush();
        $this->bindAi([]);

        $result = app(ChatService::class)->handleMessage($employee, '¿La empresa paga el gimnasio?');

        $this->assertSame('escalate', $result['outcome']);
        $this->assertSame('off_domain', $result['escalation_reason']);
        $this->assertGoldenTrace('04_admin_blocked_topic_flag_off', $result);
    }

    // =========================================================================
    // 5. explicit_request (R05) — deterministic pre-check, no AI call.
    // =========================================================================
    public function test_05_explicit_request(): void
    {
        $employee = $this->employee($this->convenio('13-05'));
        $this->bindAi([]);

        $result = app(ChatService::class)->handleMessage($employee, 'Quiero hablar con una persona de Recursos Humanos, por favor.');

        $this->assertSame('escalate', $result['outcome']);
        $this->assertSame('explicit_request', $result['escalation_reason']);
        $this->assertGoldenTrace('05_explicit_request', $result);
    }

    // =========================================================================
    // 6. off_domain / router_off_domain (R06) — the LLM router, confident.
    // =========================================================================
    public function test_06_router_off_domain(): void
    {
        $employee = $this->employee($this->convenio('13-06'));
        $this->configureAnswerModel();
        $this->bindAi([
            'route' => ['label' => 'off_domain', 'confidence' => 0.9, 'subqueries' => [], 'reason' => null, 'trace_fragment' => []],
        ]);

        $result = app(ChatService::class)->handleMessage($employee, '¿Cuál es la capital de Francia?');

        $this->assertSame('escalate', $result['outcome']);
        $this->assertSame('off_domain', $result['escalation_reason']);
        $this->assertGoldenTrace('06_router_off_domain', $result);
    }

    // =========================================================================
    // 7. low_confidence / cross_path (R07) — deterministic salary+prose compound.
    // =========================================================================
    public function test_07_salary_prose_cross_path(): void
    {
        $employee = $this->employee($this->convenio('13-07'));
        $this->bindAi([]); // pure deterministic — no AI call

        $result = app(ChatService::class)->handleMessage($employee, '¿Cuánto gano y cuántas vacaciones tengo?');

        $this->assertSame('escalate', $result['outcome']);
        $this->assertSame('low_confidence', $result['escalation_reason']);
        $this->assertSame('salary_prose_crosspath', $result['trace']['floor_decision']['path']);
        $this->assertGoldenTrace('07_cross_path', $result);
    }

    // =========================================================================
    // 8. salary_coverage_gap / statutory_figure (R08, SMI) — deterministic, no AI call.
    // =========================================================================
    public function test_08_smi_statutory_figure(): void
    {
        $employee = $this->employee($this->convenio('13-08'));
        $this->bindAi([]);

        $result = app(ChatService::class)->handleMessage($employee, '¿Cuál es el SMI este año?');

        $this->assertSame('escalate', $result['outcome']);
        $this->assertSame('salary_coverage_gap', $result['escalation_reason']);
        $this->assertGoldenTrace('08_smi_statutory_figure', $result);
    }

    // =========================================================================
    // 9. salary answer — SalaryAnswerService::OUTCOME_ANSWER, pure SQL.
    // =========================================================================
    public function test_09_salary_answer(): void
    {
        $convenio = $this->convenio('13-09');
        $category = $this->category($convenio, 'Peón', '1');
        $table = $this->salaryTable($convenio, (int) now()->year);
        SalaryTableRow::create([
            'salary_table_id' => $table->id, 'job_category_id' => $category->id,
            'gross_annual' => 21000, 'base_salary_monthly' => 1500, 'pagas_count' => 14,
        ]);
        $employee = $this->employee($convenio, jobCategoryId: $category->id);
        $this->bindAi([]);

        $result = app(ChatService::class)->handleMessage($employee, '¿Cuánto gano?');

        $this->assertSame('answer', $result['outcome']);
        $this->assertSame('salary_sql', $result['trace']['floor_decision']['path']);
        $this->assertGoldenTrace('09_salary_answer', $result);
    }

    // =========================================================================
    // 10. needs_category — SalaryAnswerService::OUTCOME_NEEDS_CATEGORY.
    // =========================================================================
    public function test_10_salary_needs_category(): void
    {
        $convenio = $this->convenio('13-10');
        $category = $this->category($convenio, 'Peón', '1');
        $table = $this->salaryTable($convenio, (int) now()->year);
        SalaryTableRow::create([
            'salary_table_id' => $table->id, 'job_category_id' => $category->id,
            'gross_annual' => 21000, 'base_salary_monthly' => 1500, 'pagas_count' => 14,
        ]);
        $employee = $this->employee($convenio, jobCategoryId: null);
        $this->bindAi([]);

        $result = app(ChatService::class)->handleMessage($employee, '¿Cuánto gano?');

        $this->assertSame('needs_category', $result['outcome']);
        $this->assertFalse($result['escalated']);
        $this->assertGoldenTrace('10_salary_needs_category', $result);
    }

    // =========================================================================
    // 11. salary_coverage_gap / no_table — SalaryAnswerService::escalate().
    // =========================================================================
    public function test_11_salary_coverage_gap_no_table(): void
    {
        $convenio = $this->convenio('13-11');
        $employee = $this->employee($convenio);
        $this->bindAi([]);

        $result = app(ChatService::class)->handleMessage($employee, '¿Cuánto gano?');

        $this->assertSame('escalate', $result['outcome']);
        $this->assertSame('salary_coverage_gap', $result['escalation_reason']);
        $this->assertGoldenTrace('11_salary_coverage_gap_no_table', $result);
    }

    // =========================================================================
    // 12. reference fact — Phase 1 quoted value (no governing prose clears
    //     Check A → composeFactWithProse short-circuits on no key; skip /ground).
    // =========================================================================
    public function test_12_reference_fact_p1_quote(): void
    {
        $convenio = $this->convenio('13-12');
        $topic = Topic::firstOrCreate(['name' => 'periodo de prueba'], ['status' => 'approved']);
        $doc = $this->doc($convenio, 'Periodos de prueba (referencia)', 'official_convenio');
        ReferenceFact::create([
            'uuid' => $this->deterministicUuid('fact-'.$convenio->numero),
            'convenio_id' => $convenio->id, 'topic_id' => $topic->id,
            'job_category_id' => null, 'group_label' => null,
            'value' => 'periodo de prueba 90/75/60 días según contrato',
            'authority_level' => ReferenceFact::AUTHORITY_LEVEL,
            'source' => 'admin_manual', 'status' => 'verified',
            'source_document_id' => $doc->id, 'source_locator' => 'p.1 §1',
        ]);
        $employee = $this->employee($convenio);
        // No answer model configured → composeFactWithProse() short-circuits
        // before ANY hr-ai call (Phase 1 quote, skip /ground) — plan §B.3.2.
        $this->bindAi([]);

        $result = app(ChatService::class)->handleMessage($employee, '¿cuál es mi periodo de prueba?');

        $this->assertSame('answer', $result['outcome']);
        $this->assertSame('reference_fact', $result['trace']['floor_decision']['path']);
        $this->assertNull($result['citations'][0]['chunk_id']);
        $this->assertGoldenTrace('12_reference_fact_p1', $result);
    }

    // =========================================================================
    // 13. reference fact — Phase 2 composition (fact + governing convenio prose,
    //     grounded, no conflict).
    // =========================================================================
    public function test_13_reference_fact_p2_composition(): void
    {
        $convenio = $this->convenio('13-13');
        $topic = Topic::firstOrCreate(['name' => 'periodo de prueba'], ['status' => 'approved']);
        $factDoc = $this->doc($convenio, 'Periodos de prueba (referencia)', 'official_convenio');
        $proseDoc = $this->doc($convenio, 'Convenio (texto)', 'official_convenio');
        ReferenceFact::create([
            'uuid' => $this->deterministicUuid('fact-'.$convenio->numero),
            'convenio_id' => $convenio->id, 'topic_id' => $topic->id,
            'job_category_id' => null, 'group_label' => null,
            'value' => 'periodo de prueba 90 días',
            'authority_level' => ReferenceFact::AUTHORITY_LEVEL,
            'source' => 'admin_manual', 'status' => 'verified',
            'source_document_id' => $factDoc->id, 'source_locator' => 'p.3 §2',
        ]);
        $employee = $this->employee($convenio);
        $this->configureAnswerModel();
        DB::table('document_chunks')->insert([
            'id' => 13130, 'document_id' => $proseDoc->id, 'chunk_index' => 0,
            'page_from' => 3, 'page_to' => 3, 'content' => 'El periodo de prueba para el personal será de 90 días naturales.',
            'token_count' => 12, 'convenio_id' => $convenio->id, 'retrieval_status' => 'active',
            'authority_level' => 'official_convenio', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->bindAi([
            'retrieve' => fn (array $p) => ($p['convenio_id'] ?? null) === null
                ? ['chunks' => [], 'eligible_total' => 0]
                : ['chunks' => [[
                    'id' => 13130, 'document_id' => $proseDoc->id, 'page_from' => 3, 'page_to' => 3,
                    'content' => 'El periodo de prueba para el personal será de 90 días naturales.',
                    'score' => 0.91, 'authority_level' => 'official_convenio',
                ]], 'eligible_total' => 1],
            'synthesise' => [
                'answer' => 'Tu periodo de prueba es de 90 días [Fuente 1][Fuente 2].',
                'confidence' => 0.92,
                'authority_used' => ['official_convenio', 'structured_reference'],
                'citations' => [
                    ['chunk_id' => 13130, 'source_type' => 'chunk', 'document_id' => $proseDoc->id, 'page_from' => 3, 'page_to' => 3, 'authority_level' => 'official_convenio'],
                    ['chunk_id' => null, 'source_type' => 'reference_fact', 'document_id' => $factDoc->id, 'authority_level' => 'structured_reference'],
                ],
                'trace_fragment' => [],
            ],
            'ground' => ['grounded' => true, 'claims' => [['claim' => 'periodo de prueba 90 días', 'grounded' => true, 'supporting_source' => 1]], 'ungrounded' => [], 'trace_fragment' => []],
        ]);

        $result = app(ChatService::class)->handleMessage($employee, '¿cuál es mi periodo de prueba?');

        $this->assertSame('answer', $result['outcome']);
        $this->assertSame('reference_fact_composition', $result['trace']['floor_decision']['path']);
        $this->assertCount(2, $result['citations']);
        $this->assertGoldenTrace('13_reference_fact_p2', $result);
    }

    // =========================================================================
    // 14. reference fact — same-point conflict (fact vs convenio) → escalate.
    // =========================================================================
    public function test_14_reference_fact_conflict(): void
    {
        $convenio = $this->convenio('13-14');
        $topic = Topic::firstOrCreate(['name' => 'periodo de prueba'], ['status' => 'approved']);
        $factDoc = $this->doc($convenio, 'Periodos de prueba (referencia)', 'official_convenio');
        $proseDoc = $this->doc($convenio, 'Convenio (texto)', 'official_convenio');
        ReferenceFact::create([
            'uuid' => $this->deterministicUuid('fact-'.$convenio->numero),
            'convenio_id' => $convenio->id, 'topic_id' => $topic->id,
            'job_category_id' => null, 'group_label' => null,
            'value' => 'periodo de prueba 90 días',
            'authority_level' => ReferenceFact::AUTHORITY_LEVEL,
            'source' => 'admin_manual', 'status' => 'verified',
            'source_document_id' => $factDoc->id, 'source_locator' => 'p.3 §2',
        ]);
        $employee = $this->employee($convenio);
        $this->configureAnswerModel();
        DB::table('document_chunks')->insert([
            'id' => 13140, 'document_id' => $proseDoc->id, 'chunk_index' => 0,
            'page_from' => 3, 'page_to' => 3, 'content' => 'El periodo de prueba será de 60 días.',
            'token_count' => 8, 'convenio_id' => $convenio->id, 'retrieval_status' => 'active',
            'authority_level' => 'official_convenio', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->bindAi([
            'retrieve' => fn (array $p) => ($p['convenio_id'] ?? null) === null
                ? ['chunks' => [], 'eligible_total' => 0]
                : ['chunks' => [[
                    'id' => 13140, 'document_id' => $proseDoc->id, 'page_from' => 3, 'page_to' => 3,
                    'content' => 'El periodo de prueba será de 60 días.',
                    'score' => 0.91, 'authority_level' => 'official_convenio',
                ]], 'eligible_total' => 1],
            // synthesise/ground deliberately absent — a same-point conflict
            // escalates BEFORE /synthesise; calling either is a bug.
        ]);

        $result = app(ChatService::class)->handleMessage($employee, '¿cuál es mi periodo de prueba?');

        $this->assertSame('escalate', $result['outcome']);
        $this->assertSame('conflict', $result['escalation_reason']);
        $this->assertSame('reference_fact_composition', $result['trace']['floor_decision']['path']);
        $this->assertGoldenTrace('14_reference_fact_conflict', $result);
    }

    // =========================================================================
    // 15. reference_fact_coverage_gap — Tier 4 (verified fact exists for the
    //     topic, but scoped to a DIFFERENT category than the employee's; no
    //     group, no convenio-wide fact — "does not confidently match one").
    // =========================================================================
    public function test_15_reference_fact_coverage_gap_tier4(): void
    {
        $convenio = $this->convenio('13-15');
        $topic = Topic::firstOrCreate(['name' => 'periodo de prueba'], ['status' => 'approved']);
        $categoryA = $this->category($convenio, 'Camarero/a', '1');
        $categoryB = $this->category($convenio, 'Cocinero/a', '2');
        $doc = $this->doc($convenio, 'Periodos de prueba (referencia)', 'official_convenio');
        ReferenceFact::create([
            'uuid' => $this->deterministicUuid('fact-'.$convenio->numero),
            'convenio_id' => $convenio->id, 'topic_id' => $topic->id,
            'job_category_id' => $categoryB->id, 'group_label' => null,
            'value' => 'periodo de prueba 60 días',
            'authority_level' => ReferenceFact::AUTHORITY_LEVEL,
            'source' => 'admin_manual', 'status' => 'verified',
            'source_document_id' => $doc->id,
        ]);
        $employee = $this->employee($convenio, jobCategoryId: $categoryA->id);
        $this->bindAi([]);

        $result = app(ChatService::class)->handleMessage($employee, '¿cuál es mi periodo de prueba?');

        $this->assertSame('escalate', $result['outcome']);
        $this->assertSame('reference_fact_coverage_gap', $result['escalation_reason']);
        $this->assertGoldenTrace('15_reference_fact_gap_tier4', $result);
    }

    // =========================================================================
    // 16. low_confidence / aggregation (R14) — vague "días libres en total".
    // =========================================================================
    public function test_16_aggregation_guard(): void
    {
        $convenio = $this->convenio('13-16');
        $employee = $this->employee($convenio);
        // No key configured → router fails safe to prose with NO hr-ai call;
        // the aggregation guard fires first inside answerProse(), before any
        // retrieval — so no AI call is expected at all.
        $this->bindAi([]);

        $result = app(ChatService::class)->handleMessage($employee, '¿Cuántos días libres tengo en total?');

        $this->assertSame('escalate', $result['outcome']);
        $this->assertSame('low_confidence', $result['escalation_reason']);
        $this->assertTrue($result['trace']['aggregation_guard']['fired']);
        $this->assertGoldenTrace('16_aggregation', $result);
    }

    // =========================================================================
    // 17. estatuto_fallback_gap (R15) — convenio prose exists, zero chunks
    //     ("expired_only" — the mid-ingest/expired shape, ADR-0032, fails closed).
    // =========================================================================
    public function test_17_estatuto_fallback_gap_expired_only(): void
    {
        $convenio = $this->convenio('13-17');
        // Active prose document, deliberately ZERO document_chunks rows.
        $this->doc($convenio, 'Convenio recién subido', 'official_convenio');
        $employee = $this->employee($convenio);
        // No key configured → router fails safe to prose with no hr-ai call;
        // the fallback classification fires before retrieval — no AI call.
        $this->bindAi([]);

        $result = app(ChatService::class)->handleMessage($employee, '¿Cuántos días de vacaciones tengo?');

        $this->assertSame('escalate', $result['outcome']);
        $this->assertSame('estatuto_fallback_gap', $result['escalation_reason']);
        $this->assertArrayNotHasKey('fallback', $result['trace']['floor_decision']);
        $this->assertGoldenTrace('17_estatuto_fallback_gap', $result);
    }

    // =========================================================================
    // 18. fallback answer (never_ingested) — the Estatuto answers alone,
    //     labelled with the FALLBACK_CAVEAT (ADR-0032).
    // =========================================================================
    public function test_18_estatuto_fallback_answer(): void
    {
        $convenio = $this->convenio('13-18'); // deliberately nothing at all
        $employee = $this->employee($convenio);
        $this->configureAnswerModel();
        // The national_law chunk's document must resolve for the citation join.
        $estatutoDoc = $this->doc(null, 'Estatuto de los Trabajadores', 'national_law', 'national_law');
        DB::table('document_chunks')->insert([
            'id' => 13180, 'document_id' => $estatutoDoc->id, 'chunk_index' => 0,
            'page_from' => 38, 'page_to' => 38, 'content' => 'El periodo de vacaciones anuales será de 30 días naturales.',
            'token_count' => 10, 'convenio_id' => null, 'retrieval_status' => 'active',
            'authority_level' => 'national_law', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->bindAi([
            'route' => ['label' => 'prose', 'confidence' => 0.9, 'subqueries' => [], 'reason' => null, 'trace_fragment' => []],
            'retrieve' => ['chunks' => [[
                'id' => 13180, 'document_id' => $estatutoDoc->id, 'page_from' => 38, 'page_to' => 38,
                'content' => 'El periodo de vacaciones anuales será de 30 días naturales.',
                'score' => 0.85, 'authority_level' => 'national_law',
            ]], 'eligible_total' => 1],
            'synthesise' => [
                'answer' => 'Según el Estatuto de los Trabajadores, las vacaciones son de 30 días naturales. [Fuente 1]',
                'citations' => [['chunk_id' => 13180, 'document_id' => $estatutoDoc->id, 'page_from' => 38, 'page_to' => 38, 'authority_level' => 'national_law']],
                'grounding_signal' => ['grounded' => true, 'citation_count' => 1, 'top_chunk_score' => 0.85],
                'confidence' => 0.9,
                'authority_used' => ['national_law'],
                'trace_fragment' => [],
            ],
            'ground' => ['grounded' => true, 'claims' => [['claim' => '30 días naturales', 'grounded' => true, 'supporting_source' => 13180]], 'ungrounded' => [], 'trace_fragment' => []],
        ]);

        $result = app(ChatService::class)->handleMessage($employee, '¿Cuántos días de vacaciones tengo?');

        $this->assertSame('answer', $result['outcome']);
        $this->assertSame('estatuto_gap', $result['trace']['floor_decision']['fallback']);
        $this->assertStringContainsString(ChatService::FALLBACK_CAVEAT, $result['answer']);
        $this->assertGoldenTrace('18_estatuto_fallback_answer', $result);
    }

    // =========================================================================
    // 19. low_confidence / no_retrieval (R16, Check A fail) — no eligible chunks.
    // =========================================================================
    public function test_19_check_a_retrieval_fail(): void
    {
        $convenio = $this->convenio('13-19');
        // At least one prose chunk anywhere on the convenio so classifyProseGap
        // = covered (fallback machinery is NOT what this case is testing).
        $doc = $this->doc($convenio, 'Convenio (texto)', 'official_convenio');
        DB::table('document_chunks')->insert([
            'id' => 13190, 'document_id' => $doc->id, 'chunk_index' => 0,
            'page_from' => 1, 'page_to' => 1, 'content' => 'texto irrelevante de otro tema',
            'token_count' => 6, 'convenio_id' => $convenio->id, 'retrieval_status' => 'active',
            'authority_level' => 'official_convenio', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $employee = $this->employee($convenio);
        // No key configured → router fails safe to prose with no hr-ai call;
        // Check A fires right after retrieval, before the key is ever needed.
        $this->bindAi(['retrieve' => ['chunks' => [], 'eligible_total' => 0]]);

        $result = app(ChatService::class)->handleMessage($employee, '¿Cuántos días de excedencia tengo?');

        $this->assertSame('escalate', $result['outcome']);
        $this->assertSame('low_confidence', $result['escalation_reason']);
        $this->assertFalse($result['trace']['floor_decision']['check_a_retrieval']);
        $this->assertGoldenTrace('19_check_a_fail', $result);
    }

    // =========================================================================
    // 20. low_confidence / citations_failed (R19, Check B fail) — hallucinated
    //     citation (chunk_id not in the provided set).
    // =========================================================================
    public function test_20_check_b_citations_fail(): void
    {
        $convenio = $this->convenio('13-20');
        $doc = $this->doc($convenio, 'Convenio (texto)', 'official_convenio');
        DB::table('document_chunks')->insert([
            'id' => 13200, 'document_id' => $doc->id, 'chunk_index' => 0,
            'page_from' => 5, 'page_to' => 5, 'content' => 'La excedencia voluntaria podrá solicitarse tras un año de antigüedad.',
            'token_count' => 10, 'convenio_id' => $convenio->id, 'retrieval_status' => 'active',
            'authority_level' => 'official_convenio', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->configureAnswerModel();
        $this->bindAi([
            'route' => ['label' => 'prose', 'confidence' => 0.9, 'subqueries' => [], 'reason' => null, 'trace_fragment' => []],
            'retrieve' => ['chunks' => [[
                'id' => 13200, 'document_id' => $doc->id, 'page_from' => 5, 'page_to' => 5,
                'content' => 'La excedencia voluntaria podrá solicitarse tras un año de antigüedad.',
                'score' => 0.80, 'authority_level' => 'official_convenio',
            ]], 'eligible_total' => 1],
            // Cites a chunk_id that was never provided — hallucinated.
            'synthesise' => [
                'answer' => 'La excedencia se concede tras un año. [Fuente 1]',
                'citations' => [['chunk_id' => 999999, 'document_id' => $doc->id, 'page_from' => 5, 'page_to' => 5, 'authority_level' => 'official_convenio']],
                'grounding_signal' => [],
                'confidence' => 0.9,
                'authority_used' => ['official_convenio'],
                'trace_fragment' => [],
            ],
        ]);

        $result = app(ChatService::class)->handleMessage($employee = $this->employee($convenio), '¿Cuánto tiempo de excedencia me corresponde?');

        $this->assertSame('escalate', $result['outcome']);
        $this->assertSame('low_confidence', $result['escalation_reason']);
        $this->assertFalse($result['trace']['floor_decision']['check_b_citations']);
        $this->assertGoldenTrace('20_check_b_fail', $result);
    }

    // =========================================================================
    // 21. low_confidence / figure_not_grounded (R20, figure-guard) — a
    //     load-bearing figure in the answer is absent from the cited chunk.
    // =========================================================================
    public function test_21_figure_guard_fail(): void
    {
        $convenio = $this->convenio('13-21');
        $doc = $this->doc($convenio, 'Convenio (texto)', 'official_convenio');
        DB::table('document_chunks')->insert([
            'id' => 13210, 'document_id' => $doc->id, 'chunk_index' => 0,
            'page_from' => 5, 'page_to' => 5, 'content' => 'La excedencia voluntaria podrá solicitarse tras un año de antigüedad.',
            'token_count' => 10, 'convenio_id' => $convenio->id, 'retrieval_status' => 'active',
            'authority_level' => 'official_convenio', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->configureAnswerModel();
        $this->bindAi([
            'route' => ['label' => 'prose', 'confidence' => 0.9, 'subqueries' => [], 'reason' => null, 'trace_fragment' => []],
            'retrieve' => ['chunks' => [[
                'id' => 13210, 'document_id' => $doc->id, 'page_from' => 5, 'page_to' => 5,
                'content' => 'La excedencia voluntaria podrá solicitarse tras un año de antigüedad.',
                'score' => 0.80, 'authority_level' => 'official_convenio',
            ]], 'eligible_total' => 1],
            // "5 años" never appears in the cited chunk text (digit or word form).
            'synthesise' => [
                'answer' => 'La excedencia dura 5 años. [Fuente 1]',
                'citations' => [['chunk_id' => 13210, 'document_id' => $doc->id, 'page_from' => 5, 'page_to' => 5, 'authority_level' => 'official_convenio']],
                'grounding_signal' => [],
                'confidence' => 0.9,
                'authority_used' => ['official_convenio'],
                'trace_fragment' => [],
            ],
        ]);

        $result = app(ChatService::class)->handleMessage($this->employee($convenio), '¿Cuánto dura la excedencia?');

        $this->assertSame('escalate', $result['outcome']);
        $this->assertSame('low_confidence', $result['escalation_reason']);
        $this->assertFalse($result['trace']['floor_decision']['figure_grounding']['grounded']);
        $this->assertGoldenTrace('21_figure_guard_fail', $result);
    }

    // =========================================================================
    // 22. low_confidence / entailment_failed (R21) — /ground rejects a claim.
    // =========================================================================
    public function test_22_entailment_fail(): void
    {
        $convenio = $this->convenio('13-22');
        $doc = $this->doc($convenio, 'Convenio (texto)', 'official_convenio');
        DB::table('document_chunks')->insert([
            'id' => 13220, 'document_id' => $doc->id, 'chunk_index' => 0,
            'page_from' => 5, 'page_to' => 5, 'content' => 'La excedencia voluntaria podrá solicitarse tras un año de antigüedad.',
            'token_count' => 10, 'convenio_id' => $convenio->id, 'retrieval_status' => 'active',
            'authority_level' => 'official_convenio', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->configureAnswerModel();
        $this->bindAi([
            'route' => ['label' => 'prose', 'confidence' => 0.9, 'subqueries' => [], 'reason' => null, 'trace_fragment' => []],
            'retrieve' => ['chunks' => [[
                'id' => 13220, 'document_id' => $doc->id, 'page_from' => 5, 'page_to' => 5,
                'content' => 'La excedencia voluntaria podrá solicitarse tras un año de antigüedad.',
                'score' => 0.80, 'authority_level' => 'official_convenio',
            ]], 'eligible_total' => 1],
            'synthesise' => [
                'answer' => 'La excedencia se concede tras un año de antigüedad. [Fuente 1]',
                'citations' => [['chunk_id' => 13220, 'document_id' => $doc->id, 'page_from' => 5, 'page_to' => 5, 'authority_level' => 'official_convenio']],
                'grounding_signal' => [],
                'confidence' => 0.9,
                'authority_used' => ['official_convenio'],
                'trace_fragment' => [],
            ],
            // The entailment gate rejects the claim (e.g. the model overstated it).
            'ground' => ['grounded' => false, 'claims' => [['claim' => 'tras un año de antigüedad', 'grounded' => false, 'supporting_source' => null]], 'ungrounded' => ['tras un año de antigüedad'], 'trace_fragment' => []],
        ]);

        $result = app(ChatService::class)->handleMessage($this->employee($convenio), '¿Cuándo puedo pedir la excedencia?');

        $this->assertSame('escalate', $result['outcome']);
        $this->assertSame('low_confidence', $result['escalation_reason']);
        $this->assertTrue($result['trace']['floor_decision']['grounding']['checked']);
        $this->assertFalse($result['trace']['floor_decision']['grounding']['grounded']);
        $this->assertGoldenTrace('22_entailment_fail', $result);
    }

    // =========================================================================
    // Slice 13d (ADR-0037) — multiple verified facts on one topic.
    //
    // 23/24/25 were RECORDED ON THE UNMODIFIED TREE FIRST (fixtures = "before":
    // the same-start tie escalated `ambiguous_conflict` for all three), then
    // deliberately re-recorded once `FactSetClassifier` landed, so the diff of
    // each fixture in git IS the behaviour change. 01–22 are untouched.
    // =========================================================================

    // 23. Two convenio-wide verified facts, same start, DISJOINT quantities
    //     (convenio 20's real 140/143 shape) + governing prose → composed answer
    //     citing each fact (Phase 2).
    public function test_23_reference_fact_set_complementary_composition(): void
    {
        $convenio = $this->convenio('13-23');
        $topic = Topic::firstOrCreate(['name' => 'jornada'], ['status' => 'approved']);
        $factDoc = $this->doc($convenio, 'Convenio (texto)', 'official_convenio');
        $this->jornadaPair($convenio, $topic, $factDoc);
        $prose = 'La jornada máxima anual será de 1704 horas en 2025. En jornada continuada de más de 6 horas '
            .'habrá un descanso de 15 minutos. Cada trabajador tendrá 2 días de libre disposición.';
        $employee = $this->employee($convenio);
        $this->configureAnswerModel();
        DB::table('document_chunks')->insert([
            'id' => 13230, 'document_id' => $factDoc->id, 'chunk_index' => 0,
            'page_from' => 9, 'page_to' => 10, 'content' => $prose,
            'token_count' => 40, 'convenio_id' => $convenio->id, 'retrieval_status' => 'active',
            'authority_level' => 'official_convenio', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->bindAi([
            'retrieve' => fn (array $p) => ($p['convenio_id'] ?? null) === null
                ? ['chunks' => [], 'eligible_total' => 0]
                : ['chunks' => [[
                    'id' => 13230, 'document_id' => $factDoc->id, 'page_from' => 9, 'page_to' => 10,
                    'content' => $prose, 'score' => 0.93, 'authority_level' => 'official_convenio',
                ]], 'eligible_total' => 1],
            // Reached only once the tie is a complementary SET (before the change it
            // escalates ahead of /synthesise, so this closure is never called).
            'synthesise' => function (string $question, array $chunks) use ($factDoc) {
                $factIds = collect($chunks)->where('source_type', 'reference_fact')->pluck('fact_id')->values()->all();

                return [
                    'answer' => 'Tu jornada máxima anual es de 1704 horas en 2025 [Fuente 2]; además, en jornadas continuadas de más de 6 horas hay un descanso de 15 minutos y tienes 2 días de libre disposición [Fuente 3].',
                    'confidence' => 0.92,
                    'authority_used' => ['official_convenio', 'structured_reference'],
                    'citations' => [
                        ['chunk_id' => null, 'source_type' => 'reference_fact', 'document_id' => $factDoc->id, 'authority_level' => 'structured_reference', 'fact_id' => $factIds[0] ?? null],
                        ['chunk_id' => null, 'source_type' => 'reference_fact', 'document_id' => $factDoc->id, 'authority_level' => 'structured_reference', 'fact_id' => $factIds[1] ?? null],
                    ],
                    'trace_fragment' => [],
                ];
            },
            'ground' => ['grounded' => true, 'claims' => [['claim' => 'jornada anual 1704 horas', 'grounded' => true, 'supporting_source' => 1]], 'ungrounded' => [], 'trace_fragment' => []],
        ]);

        $result = app(ChatService::class)->handleMessage($employee, '¿Cuál es mi jornada máxima anual?');

        $this->assertSame('answer', $result['outcome']);
        $this->assertSame('reference_fact_composition', $result['trace']['floor_decision']['path']);
        $this->assertCount(2, $result['citations']);
        $this->assertSame([true, true], array_column($result['citations'], 'is_reference_fact'));
        $fs = $result['trace']['reference_fact']['fact_set'];
        $this->assertSame('complementary', $fs['composition']);
        $this->assertCount(2, $fs['facts_selected']);
        $this->assertSame($fs['facts_selected'], $result['trace']['composition']['fact_ids_offered']);
        $this->assertSame($fs['facts_selected'], $result['trace']['composition']['fact_ids_cited']);
        $this->assertGoldenTrace('23_reference_fact_set_complementary', $result);
    }

    // 24. Same start, OVERLAPPING quantity key + different values → still
    //     escalates `ambiguous_conflict` (the safety branch). Only the added
    //     `fact_set` diagnostics may differ from the "before" recording.
    public function test_24_reference_fact_set_contradictory_escalates(): void
    {
        $convenio = $this->convenio('13-24');
        $topic = Topic::firstOrCreate(['name' => 'jornada'], ['status' => 'approved']);
        $doc = $this->doc($convenio, 'Convenio (texto)', 'official_convenio');
        foreach ([['a', '1704 horas anuales', 1704], ['b', '1720 horas anuales', 1720]] as [$tag, $value, $hours]) {
            ReferenceFact::create([
                'uuid' => $this->deterministicUuid('fact-'.$convenio->numero.'-'.$tag),
                'convenio_id' => $convenio->id, 'topic_id' => $topic->id,
                'job_category_id' => null, 'group_label' => null,
                'value' => $value, 'raw_values' => ['2025' => $hours],
                'authority_level' => ReferenceFact::AUTHORITY_LEVEL,
                'source' => 'ai_agent', 'status' => 'verified', 'validity_start' => '2025-01-01',
                'source_document_id' => $doc->id, 'source_locator' => 'p.9',
            ]);
        }
        $employee = $this->employee($convenio);
        $this->bindAi([]); // escalates in the service — no hr-ai call may happen

        $result = app(ChatService::class)->handleMessage($employee, '¿Cuál es mi jornada máxima anual?');

        $this->assertSame('escalate', $result['outcome']);
        $this->assertSame('reference_fact_coverage_gap', $result['escalation_reason']);
        $rf = $result['trace']['reference_fact'];
        $this->assertSame('ambiguous_conflict', $rf['validity_selection']);
        $this->assertSame('conflict', $rf['fact_set']['composition']);
        $this->assertSame('same_quantity', $rf['fact_set']['pairs'][0]['reason']);
        $this->assertSame(['2025'], $rf['fact_set']['pairs'][0]['shared_keys']);
        $this->assertGoldenTrace('24_reference_fact_set_contradictory', $result);
    }

    // 25. The complementary pair on the Phase 1 path (no answer model → bare
    //     quote of every fact, /ground skipped).
    public function test_25_reference_fact_set_complementary_phase1_quote(): void
    {
        $convenio = $this->convenio('13-25');
        $topic = Topic::firstOrCreate(['name' => 'jornada'], ['status' => 'approved']);
        $doc = $this->doc($convenio, 'Convenio (texto)', 'official_convenio');
        $this->jornadaPair($convenio, $topic, $doc);
        $employee = $this->employee($convenio);
        $this->bindAi([]); // no answer model → Phase 1; exploding AI proves no synthesise/ground

        $result = app(ChatService::class)->handleMessage($employee, '¿Cuál es mi jornada máxima anual?');

        $this->assertSame('answer', $result['outcome']);
        $this->assertSame('reference_fact', $result['trace']['floor_decision']['path']);
        $this->assertStringContainsString('1704 horas', $result['answer']);
        $this->assertStringContainsString('2 días de libre disposición', $result['answer']);
        $this->assertLessThan(strpos($result['answer'], '2 días de libre'), strpos($result['answer'], '1704 horas'), 'figure-bearing fact first');
        $this->assertCount(2, $result['citations']);
        $this->assertArrayNotHasKey('grounding', $result['trace']['floor_decision']);
        $this->assertGoldenTrace('25_reference_fact_set_complementary_phase1', $result);
    }

    // ---- helpers --------------------------------------------------------------

    /** Convenio 20's real facts 140 / 143 (staging), same document, same start, disjoint `raw_values`. */
    private function jornadaPair(Convenio $convenio, Topic $topic, Document $doc): void
    {
        $common = [
            'convenio_id' => $convenio->id, 'topic_id' => $topic->id,
            'job_category_id' => null, 'group_label' => null,
            'authority_level' => ReferenceFact::AUTHORITY_LEVEL,
            'source' => 'ai_agent', 'status' => 'verified',
            'validity_start' => '2025-01-01', 'validity_end' => '2028-12-31',
            'source_document_id' => $doc->id,
        ];
        ReferenceFact::create($common + [
            'uuid' => $this->deterministicUuid('fact-'.$convenio->numero.'-140'),
            'value' => 'Con carácter general, salvo para los Técnicos de Actividad deportiva y los Técnicos de Sala, que tienen jornada propia: Jornada anual a tiempo completo con carácter general (art. 84.2 y 34 ET): Año 2025: 1704 horas de trabajo efectivo; Año 2026: 1700 horas; Año 2027: 1696 horas; Año 2028: 1692 horas.',
            'raw_values' => ['2025' => '1704 horas', '2026' => '1700 horas', '2027' => '1696 horas', '2028' => '1692 horas'],
            'source_locator' => 'p9',
        ]);
        ReferenceFact::create($common + [
            'uuid' => $this->deterministicUuid('fact-'.$convenio->numero.'-143'),
            'value' => 'Reglas generales de jornada aplicables a todo el personal: en jornadas diarias continuadas de más de 6 horas se establece un descanso de 15 minutos, que no tiene la consideración de tiempo de trabajo efectivo; cada trabajador podrá disfrutar, en cada año de vigencia del convenio, de 2 días de libre disposición de carácter no recuperable.',
            'raw_values' => [
                'jornada_irregular' => '0%', 'computo_tiempo_trabajo' => 'en el puesto de trabajo',
                'dias_libre_disposicion' => '2 días', 'descanso_jornada_continuada' => '15 minutos',
            ],
            'source_locator' => 'p10',
        ]);
    }

    private function convenio(string $numero): Convenio
    {
        return Convenio::create([
            'numero' => '31TEST-'.$numero, 'name' => 'Convenio '.$numero,
            'territory_id' => $this->territory->id, 'sector_id' => $this->sector->id,
        ]);
    }

    private function employee(Convenio $convenio, ?int $jobCategoryId = null, ?int $groupId = null): Employee
    {
        // A DETERMINISTIC uuid/email (derived from the convenio number, which is
        // itself fixed per case) — `uniqid()`/`Str::uuid()` would make the golden
        // fixture non-reproducible across runs for a reason that has nothing to
        // do with the refactor under test. `uuid` is a real Postgres uuid column,
        // so the deterministic value must still be shaped like one.
        $slug = str_replace(['31TEST-', ' '], ['', '-'], $convenio->numero);

        return Employee::create([
            'uuid' => $this->deterministicUuid("emp-{$slug}"),
            'email' => "emp-{$slug}@example.com",
            'full_name' => 'Empleada de prueba',
            'convenio_id' => $convenio->id, 'job_category_id' => $jobCategoryId,
            'convenio_group_id' => $groupId,
            'territory_id' => $this->territory->id, 'employment_type' => 'full_time', 'status' => 'active',
        ]);
    }

    /** A stable, valid-shaped (8-4-4-4-12 hex) uuid derived from a fixture key. */
    private function deterministicUuid(string $seed): string
    {
        $hex = substr(hash('sha256', $seed), 0, 32);
        $parts = str_split($hex, 4);

        return "{$parts[0]}{$parts[1]}-{$parts[2]}-{$parts[3]}-{$parts[4]}-{$parts[5]}{$parts[6]}{$parts[7]}";
    }

    private function category(Convenio $convenio, string $name, string $groupCode): ConvenioJobCategory
    {
        return ConvenioJobCategory::create(['convenio_id' => $convenio->id, 'name' => $name, 'group_code' => $groupCode]);
    }

    private function salaryTable(Convenio $convenio, int $year): SalaryTable
    {
        return SalaryTable::create(['convenio_id' => $convenio->id, 'year' => $year]);
    }

    /** @param 'official_convenio'|'national_law' $authority */
    private function doc(?Convenio $convenio, string $title, string $authority, string $typeCode = 'convenio_text'): Document
    {
        return Document::create([
            'uuid' => $this->deterministicUuid('doc-'.($convenio?->numero ?? 'none').'-'.$title),
            'title' => $title,
            'storage_path' => 'fake/'.$this->deterministicUuid('path-'.($convenio?->numero ?? 'none').'-'.$title),
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

    /**
     * Bind a scripted ExtractionClient. Each key ('route'|'retrieve'|
     * 'synthesise'|'ground') is a fixed array or a callable(...$args): array.
     * Calling a method whose key is absent from $script throws — the fixture
     * declares exactly which hr-ai calls this case is allowed to make.
     *
     * @param  array<string, array<string,mixed>|callable>  $script
     */
    private function bindAi(array $script): void
    {
        $fake = new class($script) extends ExtractionClient
        {
            public function __construct(private array $script) {}

            private function resolve(string $key, array $args): array
            {
                if (! array_key_exists($key, $this->script)) {
                    throw new \RuntimeException("golden-trace fixture: unexpected /{$key} call");
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

    /**
     * Compare the deterministic slice of the response payload against a
     * checked-in JSON fixture (recorded on first run — see the class docblock).
     *
     * @param  array<string,mixed>  $result
     */
    /**
     * Keys whose values are real Postgres autoincrement PKs (Convenio,
     * Document, ReferenceFact, ConvenioJobCategory, SalaryTable, Territory,
     * Topic). `RefreshDatabase` rolls back each test's rows but NOT the
     * sequence counters (`nextval()` isn't transactional), so these raw
     * integers drift depending on how many rows earlier tests in the SAME
     * process created — they are deterministic when this class runs alone,
     * but NOT when it runs as part of the full suite. `chunk_id`/`table_id`
     * on scripted `document_chunks` rows are excluded on purpose: those are
     * explicit literal ids set by the fixture itself (e.g. 13130), not
     * sequence-generated, so they're already stable and worth comparing
     * as-is.
     */
    private const NORMALIZED_ID_KEYS = [
        'convenio_id', 'document_id', 'fact_id', 'job_category_id', 'table_id', 'territory_id', 'topic_id',
    ];

    /** Slice 13d — list-valued keys holding fact ids (see `normalizeAutoincrementIds`). */
    private const NORMALIZED_FACT_ID_LISTS = ['facts_selected', 'facts_omitted', 'fact_ids_offered', 'fact_ids_cited'];

    /** Bucket the value should be recorded/looked-up under, given the (key, value) pair. Handles the one exception — `categories[].id`, which is a `job_category_id` under a bare `id` key. */
    private function normalizedIdBucket(string $key): ?string
    {
        if (in_array($key, self::NORMALIZED_ID_KEYS, true)) {
            return $key;
        }
        if ($key === 'id') {
            return 'job_category_id'; // only reached from within a `categories[]` entry — see below.
        }

        return null;
    }

    /** Replaces each autoincrement id with a placeholder keyed by (fieldName, order-of-first-appearance) — stable regardless of the absolute sequence position. Also normalizes the one place a raw id leaks into free text (`floor_decision.note`'s "(fact_id N)"). */
    private function normalizeAutoincrementIds(mixed $value, array &$seen): mixed
    {
        if (is_string($value)) {
            return preg_replace_callback(
                '/\((fact_id|document_id|convenio_id|job_category_id|table_id|territory_id|topic_id) (\d+)\)/',
                function (array $m) use (&$seen) {
                    $seen[$m[1]] ??= [];
                    $seen[$m[1]][(int) $m[2]] ??= count($seen[$m[1]]) + 1;

                    return "({$m[1]} #{$seen[$m[1]][(int) $m[2]]})";
                },
                $value
            );
        }

        if (! is_array($value)) {
            return $value;
        }

        $out = [];
        foreach ($value as $k => $v) {
            // Slice 13d: fact ids also ride in LISTS (`fact_set.facts_selected`, `composition.fact_ids_*`)
            // and as a pair's `a`/`b`. Same sequence-drift problem, same placeholder family (`fact_id`).
            // Only keys that did not exist before 13d — every 01–22 fixture is unaffected.
            if (is_string($k) && in_array($k, self::NORMALIZED_FACT_ID_LISTS, true) && is_array($v)) {
                $out[$k] = array_map(function ($id) use (&$seen) {
                    if (! is_int($id)) {
                        return $id;
                    }
                    $seen['fact_id'] ??= [];
                    $seen['fact_id'][$id] ??= count($seen['fact_id']) + 1;

                    return "#fact_id:{$seen['fact_id'][$id]}";
                }, $v);

                continue;
            }
            if (($k === 'a' || $k === 'b') && is_int($v) && isset($value['relation'], $value['reason'])) {
                $seen['fact_id'] ??= [];
                $seen['fact_id'][$v] ??= count($seen['fact_id']) + 1;
                $out[$k] = "#fact_id:{$seen['fact_id'][$v]}";

                continue;
            }
            $bucket = is_string($k) && is_int($v) ? $this->normalizedIdBucket($k) : null;
            if ($bucket !== null && $k === 'id' && ! isset($value['group_code'])) {
                $bucket = null; // a bare `id` outside of a categories[] entry is NOT a known id family — leave it alone.
            }
            if ($bucket !== null) {
                $seen[$bucket] ??= [];
                $seen[$bucket][$v] ??= count($seen[$bucket]) + 1;
                $out[$k] = "#{$k}:{$seen[$bucket][$v]}";
            } elseif ($k === 'as_of_date' && is_string($v) && $v === Carbon::today()->toDateString()) {
                // `ChatService` stamps today on every turn (`scope_filters.as_of_date`).
                // A literal date in the fixture would fail the morning after it
                // was recorded — same class of non-determinism as the sequence
                // ids above, different clock.
                $out[$k] = '#as_of_date:today';
            } else {
                $out[$k] = $this->normalizeAutoincrementIds($v, $seen);
            }
        }

        return $out;
    }

    /**
     * Sprint 13, F.13-adjacent follow-up (post-CP-0 review comment (a)): the
     * comparator must prove the FULL PERSISTED TURN is byte-identical, not
     * only the trace. `$result` (the `TurnPersister::persist()` return value)
     * already carries `answer` — which is the EMPLOYEE-FACING string, i.e. it
     * already includes both the ADR-0029 escalation override
     * (`EMPLOYEE_ESCALATION_MESSAGE`) and the Sprint 10a Estatuto caveat
     * (`decorate()`/`FALLBACK_CAVEAT`) baked in — so "answer text" and
     * "caveat" are already covered by the existing `answer` key below; this
     * method additionally fetches and compares the escalation_cards ROW
     * (`explanation_facts`/`fix_action`/`fix_surface`/`fix_link`/`reason`),
     * which was previously NOT part of the comparison at all. `card_facts`
     * is deterministic — `EscalationExplainer::explain($reason, $trace)` is a
     * pure function of the already-compared `reason` + `trace` — but it is
     * asserted directly here rather than left as an inference, since a
     * refactor bug in a caller that passes the wrong `$trace` slice to the
     * explainer would otherwise pass unnoticed.
     */
    private function assertGoldenTrace(string $case, array $result): void
    {
        $cardFacts = null;
        if ($result['escalated'] && $result['escalation_uuid']) {
            $card = EscalationCard::where('uuid', $result['escalation_uuid'])->first();
            if ($card) {
                $cardFacts = [
                    'reason' => $card->reason,
                    'explanation_facts' => $card->explanation_facts,
                    'fix_action' => $card->fix_action,
                    'fix_surface' => $card->fix_surface,
                    'fix_link' => $card->fix_link,
                ];
            }
        }

        $payload = [
            'outcome' => $result['outcome'],
            'escalated' => $result['escalated'],
            'escalation_reason' => $result['escalation_reason'],
            'answer' => $result['answer'],
            'citations' => $result['citations'],
            'categories' => $result['categories'],
            'authority_used' => $result['authority_used'],
            'trace' => $result['trace'],
            'escalation_card_facts' => $cardFacts,
        ];
        $seen = [];
        $payload = $this->normalizeAutoincrementIds($payload, $seen);
        $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";

        $path = __DIR__.'/../Fixtures/golden-traces/'.$case.'.json';
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        if (! file_exists($path)) {
            file_put_contents($path, $encoded);
            $this->fail("golden trace fixture recorded for '{$case}' at {$path} — re-run the test to verify it (this run only captured the baseline).");
        }

        $expected = file_get_contents($path);
        $this->assertJsonStringEqualsJsonString($expected, $encoded, "golden trace for '{$case}' changed — see {$path}");
    }
}
