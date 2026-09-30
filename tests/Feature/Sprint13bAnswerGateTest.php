<?php

namespace Tests\Feature;

use App\Models\Convenio;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Employee;
use App\Models\ReferenceFact;
use App\Models\Sector;
use App\Models\Territory;
use App\Models\Topic;
use App\Services\Agent\PlannerClient;
use Database\Seeders\DocumentTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Sprint 13b, build step 7 — `answer:gate` mechanics added for the normalization gate (plan.md §7.1/§7.3/§7.4):
 * the frozen-bank sha check, `path_not` scoring, the outcome-independent `phrasing`/`anchored` labels and their 2×2,
 * the `norm.*` row fields and aggregates (rescues LISTED), `normalization_validation` excluded from corrections,
 * and the projected/measured spend guard. No live provider: a scripted planner.
 */
class Sprint13bAnswerGateTest extends TestCase
{
    use RefreshDatabase;

    private Territory $territory;

    private Convenio $convenio;

    private Topic $topic;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DocumentTypeSeeder::class);
        $this->territory = Territory::create(['code' => '31', 'name' => 'Navarra', 'level' => 'provincial', 'aliases' => []]);
        $sector = Sector::create(['name' => 'Hostelería', 'aliases' => []]);
        $this->convenio = Convenio::create(['numero' => '13BG-1', 'name' => 'Convenio Gate B', 'territory_id' => $this->territory->id, 'sector_id' => $sector->id]);
        $this->topic = Topic::firstOrCreate(['name' => 'periodo de prueba'], ['status' => 'approved']);
        $doc = Document::create([
            'uuid' => (string) Str::uuid(), 'title' => 'Periodos de prueba (referencia)', 'storage_path' => 'fake/'.Str::uuid(),
            'convenio_id' => $this->convenio->id, 'document_type_id' => DocumentType::where('code', 'convenio_text')->value('id'),
            'authority_level' => 'official_convenio', 'retrieval_status' => 'active', 'language' => 'es', 'tagging_status' => 'verified',
        ]);
        ReferenceFact::create([
            'convenio_id' => $this->convenio->id, 'topic_id' => $this->topic->id, 'job_category_id' => null, 'group_label' => null,
            'value' => 'periodo de prueba de 90 días', 'authority_level' => ReferenceFact::AUTHORITY_LEVEL, 'source' => 'admin_manual',
            'status' => 'verified', 'source_document_id' => $doc->id, 'source_locator' => 'p.1',
        ]);
    }

    private function employee(): Employee
    {
        return Employee::create([
            'email' => 'gate13b@example.com', 'full_name' => 'Gate 13b', 'convenio_id' => $this->convenio->id,
            'territory_id' => $this->territory->id, 'employment_type' => 'full_time', 'status' => 'active',
        ]);
    }

    /** @return array{0:string,1:string} [dir, path] */
    private function bank(array $cases): array
    {
        $dir = sys_get_temp_dir().'/bank'.Str::random(8);
        mkdir($dir);
        $path = $dir.'/bank.json';
        file_put_contents($path, json_encode(['cases' => $cases], JSON_UNESCAPED_UNICODE));

        return [$dir, $path];
    }

    private function gateJson(array $args): array
    {
        Artisan::call('answer:gate', $args + ['--json' => true]);

        return json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function lookupCase(string $email, string $id = 'lk-1', bool $anchored = false): array
    {
        return [
            'id' => $id, 'bank' => 'held_out', 'class' => 'fact_convenio_wide', 'authored' => 'colloquial', 'anchored' => $anchored,
            'email' => $email, 'colloquial_question' => 'Empecé la semana pasada, ¿cuánto dura la etapa de prueba?',
            'expect' => ['outcome' => 'answer', 'path' => ['reference_fact', 'reference_fact_composition'], 'value_contains' => ['90']],
        ];
    }

    public function test_a_frozen_bank_whose_sha_differs_from_the_manifest_is_refused_and_an_unchanged_one_runs(): void
    {
        [$dir, $path] = $this->bank([[
            'id' => 'sens', 'question' => 'Estoy sufriendo acoso en el trabajo, ¿qué hago?',
            'expect' => ['outcome' => 'escalate', 'reason' => 'sensitive_topic'], 'email' => $this->employee()->email,
        ]]);

        file_put_contents($dir.'/MANIFEST.sha256', str_repeat('0', 64)."  bank.json\n");
        $this->artisan('answer:gate', ['--engine' => 'classic', '--set' => $path])->expectsOutputToContain('FROZEN BANK CHANGED')->assertExitCode(1);
        $this->artisan('answer:gate', ['--engine' => 'classic', '--set' => $path, '--allow-unfrozen' => true])->assertExitCode(0);

        file_put_contents($dir.'/MANIFEST.sha256', hash_file('sha256', $path)."  bank.json\n");
        $this->artisan('answer:gate', ['--engine' => 'classic', '--set' => $path])->assertExitCode(0);
    }

    public function test_the_real_frozen_banks_match_the_manifest(): void
    {
        $eval = base_path('../hr-docs/sprints/sprint-13b/eval');
        if (! is_file($eval.'/MANIFEST.sha256')) {
            $this->markTestSkipped('hr-docs is not checked out next to hr-backend.');
        }
        foreach (['fact-routing-colloquial.json', 'fact-routing-colloquial-dev.json'] as $bank) {
            $this->artisan('answer:gate', ['--engine' => 'classic', '--set' => $eval.'/'.$bank, '--filter' => '^no-such-case$'])->assertExitCode(0);
        }
    }

    public function test_the_projected_spend_guard_refuses_to_start_an_over_budget_run(): void
    {
        $email = $this->employee()->email;
        [, $path] = $this->bank([$this->lookupCase($email, 'a'), $this->lookupCase($email, 'b')]);

        $this->artisan('answer:gate', ['--engine' => 'agent', '--set' => $path, '--repeat' => 3, '--budget-usd' => '0.10', '--est-turn-usd' => '0.05'])
            ->expectsOutputToContain('refusing to start')
            ->assertExitCode(1);
    }

    public function test_path_not_scores_a_situational_case_and_labels_reach_the_two_by_two(): void
    {
        $email = $this->employee()->email;
        [, $path] = $this->bank([
            [
                'id' => 'sit-1', 'bank' => 'held_out', 'class' => 'colloquial_situational', 'authored' => 'colloquial', 'anchored' => false,
                'email' => $email, 'question' => 'Estoy sufriendo acoso en el trabajo, ¿qué hago?',
                'expect' => ['path_not' => ['reference_fact']],
            ],
            [
                'id' => 'canon-1', 'class' => 'fact_convenio_wide', 'authored' => 'canonical', 'anchored' => true,
                'email' => $email, 'question' => 'Estoy sufriendo acoso en el trabajo, ¿qué hago?',
                'expect' => ['outcome' => 'escalate'],
            ],
        ]);

        $out = $this->gateJson(['--engine' => 'classic', '--set' => $path]);

        $rows = collect($out['rows'])->keyBy('id');
        $this->assertTrue($rows['sit-1']['pass'], 'an escalation is an acceptable non-fact route');
        $this->assertTrue($rows['sit-1']['situational']);
        $this->assertSame('colloquial', $rows['sit-1']['phrasing']);
        $this->assertFalse($rows['sit-1']['anchored']);
        $cells = $out['summary']['engines']['classic']['by_phrasing_anchor'];
        $this->assertSame(['n' => 1, 'pass' => 1], $cells['colloquial|unanchored|situational']);
        $this->assertSame(['n' => 1, 'pass' => 1], $cells['canonical|anchored']);
        $this->assertNull($rows['sit-1']['norm'], 'classic has no normalization block');

        // A Sprint 13-style case (no `anchored` label, canonical + colloquial variants) still lands in a phrasing cell.
        $out2 = $this->gateJson(['--engine' => 'classic', '--set' => $this->bank([[
            'id' => 'old', 'email' => $email, 'canonical_question' => 'Estoy sufriendo acoso en el trabajo, ¿qué hago?',
            'colloquial_question' => 'Me acosan en el curro, ¿qué hago?', 'expect' => ['outcome' => 'escalate'],
        ]])[1]]);
        $cells2 = $out2['summary']['engines']['classic']['by_phrasing_anchor'];
        $this->assertArrayHasKey('canonical|unlabelled', $cells2);
        $this->assertArrayHasKey('colloquial|unlabelled', $cells2);
    }

    public function test_agent_rows_carry_the_norm_fields_and_rescues_are_listed_and_not_counted_as_corrections(): void
    {
        $email = $this->employee()->email;
        $topicId = $this->topic->id;
        $calls = [
            [['id' => 'n1', 'tool' => 'normalize_question', 'input' => ['topic_id' => $topicId, 'canonical_query' => 'duración del período de prueba', 'confidence' => 0.9, 'reason' => 'coloquial']]],
            [['id' => 'n1', 'tool' => 'normalize_question', 'input' => ['topic_id' => $topicId, 'canonical_query' => 'duración del período de prueba: 90 días', 'confidence' => 0.9, 'reason' => 'coloquial']], ['id' => 't1', 'tool' => 'reference_fact', 'input' => []]],
        ];
        $this->app->instance(PlannerClient::class, new class($calls) implements PlannerClient
        {
            private int $turn = -1;

            public function __construct(private array $scripts) {}

            public function plan(string $question, array $scopeSummary, array $window, array $toolDefinitions, array $priorSteps): array
            {
                if ($priorSteps === []) {
                    $this->turn++; // the first round of a new turn carries no prior steps
                }
                $c = $priorSteps === [] ? $this->scripts[$this->turn] : [['id' => 'f', 'tool' => 'finalize', 'input' => ['use' => []]]];

                return ['calls' => $c, 'stop_reason' => 'tool_use', 'model' => 'm', 'request_id' => 'r', 'prompt_version' => 'sha256:gate', 'tokens' => [], 'ms' => 0];
            }
        });
        [, $path] = $this->bank([$this->lookupCase($email, 'accepted'), $this->lookupCase($email, 'rejected')]);

        $out = $this->gateJson(['--engine' => 'agent', '--set' => $path]);

        $rows = collect($out['rows'])->keyBy('id');
        $a = $rows['accepted.colloquial']['norm'];
        $this->assertSame('accepted', $a['verdict']);
        $this->assertSame($topicId, $a['topic_id']);
        $this->assertTrue($a['round1a_ran']);
        $this->assertTrue($a['rescued_answer']);
        $this->assertSame('sha256:gate', $a['planner_prompt_version']);
        $this->assertSame('reference_fact', $rows['accepted.colloquial']['first_tool'], 'normalize_question is a control call, not the first routing tool');
        $this->assertSame('reference_fact', $rows['rejected.colloquial']['first_tool']);
        $this->assertTrue($rows['accepted.colloquial']['pass']);

        $r = $rows['rejected.colloquial']['norm'];
        $this->assertSame('rejected', $r['verdict']);
        $this->assertContains('figure_not_in_literal', $r['rejected_by']);
        $this->assertFalse($rows['rejected.colloquial']['pass'], 'a rejected normalization on an unanchored question is the literal path: no fact');
        $this->assertSame(0.0, (float) $rows['rejected.colloquial']['rule_overrides'], 'a rejected normalization is not a routing correction');

        $norm = $out['summary']['engines']['agent']['norm'];
        $this->assertSame(['accepted' => 1, 'rejected' => 1], $norm['verdicts']);
        $this->assertSame(1, $norm['rescued_answers']);
        $this->assertSame('accepted.colloquial', $norm['rescued_answers_listed'][0]['id']);
        $this->assertSame(['figure_not_in_literal' => 1] + array_intersect_key($norm['rejected_by'], ['scan:F1' => 1]), $norm['rejected_by']);
        $this->assertSame(['n' => 2, 'pass' => 1], $out['summary']['engines']['agent']['by_phrasing_anchor']['colloquial|unanchored']);
    }

    public function test_the_unanchored_breakdown_separates_convenio_wide_from_group_unbound_and_situational_and_flags_the_stop_condition(): void
    {
        $email = $this->employee()->email;
        // classic engine ⇒ the plain lookup answers fail (no fact) — a deterministic 0-pass convenio-wide cell
        $group = $this->lookupCase($email, 'gb-1') + [];
        $group['class'] = 'fact_group_labelled_unbound';
        $group['expect'] = ['outcome' => 'escalate', 'reason' => 'coverage_gap'];
        $situ = $this->lookupCase($email, 'st-1');
        $situ['class'] = 'colloquial_situational';
        $situ['expect'] = ['outcome' => 'answer', 'path_not' => ['reference_fact']];
        $anchored = $this->lookupCase($email, 'an-1', true);
        [, $path] = $this->bank([$this->lookupCase($email, 'cw-1'), $this->lookupCase($email, 'cw-2'), $group, $situ, $anchored]);

        $out = $this->gateJson(['--engine' => 'classic', '--set' => $path]);
        $u = $out['summary']['engines']['classic']['unanchored_breakdown'];

        $this->assertSame(2, $u['convenio_wide']['n'], 'the anchored case is not in G1\'s population');
        $this->assertSame(1, $u['group_unbound']['n']);
        $this->assertSame(1, $u['situational_informational']['n']);
        $this->assertSame(3, $u['unanchored_overall']['n'], 'overall = convenio-wide + group-unbound, no situational');
        $this->assertSame(0, $u['convenio_wide']['pass']);
        $this->assertSame(0.0, (float) $u['convenio_wide']['rate']);
        $this->assertStringContainsString('convenio-wide 0/2 = 0% < 50%', (string) $u['stop_condition']);

        // the text report says so, loudly
        $this->artisan('answer:gate', ['--engine' => 'classic', '--set' => $path])
            ->expectsOutputToContain('STOP CONDITION: convenio-wide 0/2 = 0% < 50%')
            ->expectsOutputToContain('colloquial-unanchored (G1): overall');
    }

    public function test_no_stop_condition_when_convenio_wide_is_at_or_above_half(): void
    {
        $email = $this->employee()->email;
        $calls = [
            [['id' => 'n1', 'tool' => 'normalize_question', 'input' => ['topic_id' => $this->topic->id, 'canonical_query' => 'duración del período de prueba', 'confidence' => 0.9, 'reason' => 'coloquial']]],
            [['id' => 't1', 'tool' => 'reference_fact', 'input' => []]],
        ];
        $this->app->instance(PlannerClient::class, new class($calls) implements PlannerClient
        {
            private int $turn = -1;

            public function __construct(private array $scripts) {}

            public function plan(string $question, array $scopeSummary, array $window, array $toolDefinitions, array $priorSteps): array
            {
                if ($priorSteps === []) {
                    $this->turn++;
                }
                $c = $priorSteps === [] ? $this->scripts[$this->turn] : [['id' => 'f', 'tool' => 'finalize', 'input' => ['use' => []]]];

                return ['calls' => $c, 'stop_reason' => 'tool_use', 'model' => 'm', 'request_id' => 'r', 'prompt_version' => 'sha256:gate', 'tokens' => [], 'ms' => 0];
            }
        });
        [, $path] = $this->bank([$this->lookupCase($email, 'cw-1'), $this->lookupCase($email, 'cw-2')]);

        $u = $this->gateJson(['--engine' => 'agent', '--set' => $path])['summary']['engines']['agent']['unanchored_breakdown'];

        $this->assertSame(1, $u['convenio_wide']['pass']);
        $this->assertSame(2, $u['convenio_wide']['n']);
        $this->assertNull($u['stop_condition'], '1/2 = 50% is not below 50%');
    }
}
