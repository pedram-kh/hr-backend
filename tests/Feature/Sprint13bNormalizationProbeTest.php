<?php

namespace Tests\Feature;

use App\Console\Commands\NormalizationProbe;
use App\Models\ChatSession;
use App\Models\Convenio;
use App\Models\Employee;
use App\Models\Sector;
use App\Models\Territory;
use App\Models\Topic;
use App\Services\Agent\PlannerClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Sprint 13b — `normalization:probe`: one planner round-1 call per question + the real validator, no tools,
 * nothing persisted, an INDEPENDENT oracle for "escaped" (accepted but over-reaching).
 */
class Sprint13bNormalizationProbeTest extends TestCase
{
    use RefreshDatabase;

    private Topic $topic;

    protected function setUp(): void
    {
        parent::setUp();
        $territory = Territory::create(['code' => '31', 'name' => 'Navarra', 'level' => 'provincial', 'aliases' => []]);
        $sector = Sector::create(['name' => 'Hostelería', 'aliases' => []]);
        $convenio = Convenio::create(['numero' => '13BP-1', 'name' => 'Convenio Probe', 'territory_id' => $territory->id, 'sector_id' => $sector->id]);
        Employee::create(['email' => 'probe@example.com', 'full_name' => 'Probe', 'convenio_id' => $convenio->id, 'territory_id' => $territory->id, 'employment_type' => 'full_time', 'status' => 'active']);
        $this->topic = Topic::firstOrCreate(['name' => 'periodo de prueba'], ['status' => 'approved']);
    }

    /** @param  list<array<string,mixed>|null>  $inputs  one normalize_question input per call (null = the planner did not use it) */
    private function planner(array $inputs): object
    {
        $planner = new class($inputs) implements PlannerClient
        {
            public int $calls = 0;

            public array $offered = [];

            public function __construct(private array $inputs) {}

            public function plan(string $question, array $scopeSummary, array $window, array $toolDefinitions, array $priorSteps): array
            {
                $this->offered = array_column($toolDefinitions, 'name');
                $in = $this->inputs[$this->calls++];
                $calls = [['id' => 'r', 'tool' => 'reference_fact', 'input' => []]];
                if ($in !== null) {
                    array_unshift($calls, ['id' => 'n', 'tool' => 'normalize_question', 'input' => $in]);
                }

                return ['calls' => $calls, 'stop_reason' => 'tool_use', 'model' => 'm', 'request_id' => 'r', 'prompt_version' => 'sha256:probe', 'tokens' => ['prompt' => 1000, 'completion' => 100], 'ms' => 5];
            }
        };
        $this->app->instance(PlannerClient::class, $planner);

        return $planner;
    }

    private function file(array $cases): string
    {
        $dir = sys_get_temp_dir().'/probe'.Str::random(8);
        mkdir($dir);
        file_put_contents($dir.'/neg.json', json_encode(['cases' => $cases], JSON_UNESCAPED_UNICODE));

        return $dir.'/neg.json';
    }

    private function run13b(array $args): array
    {
        Artisan::call('normalization:probe', $args + ['--json' => true]);

        return json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_it_reports_verdicts_costs_and_offers_the_control_tool_without_running_any_tool_or_persisting(): void
    {
        $t = $this->topic->id;
        $planner = $this->planner([
            ['topic_id' => $t, 'canonical_query' => 'duración del período de prueba', 'confidence' => 0.9, 'reason' => 'coloquial'],
            ['topic_id' => $t, 'canonical_query' => 'duración del período de prueba: 90 días', 'confidence' => 0.9, 'reason' => 'x'],
            null,
        ]);
        $file = $this->file([
            ['id' => 'nn-a', 'literal' => 'Empecé ayer, ¿cuánto dura la etapa de prueba?', 'over_reach_canonical' => 'x', 's1_live' => true],
            ['id' => 'nn-b', 'literal' => '¿cuántos días me dan si me caso?', 'over_reach_canonical' => 'x', 's1_live' => true],
            ['id' => 'nn-c', 'literal' => '¿puedo irme antes?', 'over_reach_canonical' => 'x', 's1_live' => false],
        ]);
        $sessions = ChatSession::count();

        $out = $this->run13b(['--set' => $file, '--email' => 'probe@example.com', '--allow-unfrozen' => true]);

        $rows = collect($out['rows'])->keyBy('id');
        $this->assertSame('accepted', $rows['nn-a']['verdict']);
        $this->assertSame('rejected', $rows['nn-b']['verdict']);
        $this->assertContains('figure_not_in_literal', $rows['nn-b']['rejected_by']);
        $this->assertSame('absent', $rows['nn-c']['verdict']);
        $this->assertSame(0, $out['summary']['escaped']);
        $this->assertSame(['accepted' => 1, 'rejected' => 1, 'absent' => 1], $out['summary']['verdicts']);
        $this->assertEqualsWithDelta(0.0045, $rows['nn-a']['cost_usd'], 1e-6, '1000 in / 100 out at $3/$15 per MTok');
        $this->assertContains('normalize_question', $planner->offered);
        $this->assertSame($sessions, ChatSession::count(), 'every probe case is rolled back');
    }

    public function test_the_oracle_is_independent_of_the_validator_and_flags_added_digits_words_and_names(): void
    {
        $oracle = new \ReflectionMethod(NormalizationProbe::class, 'oracle');
        $probe = app(NormalizationProbe::class);
        $flags = fn (string $literal, string $canonical) => $oracle->invoke($probe, $literal, $canonical);

        $this->assertSame([], $flags('¿cuántos días me dan si me caso?', 'permiso por matrimonio'));
        $this->assertSame(['audit:digit', 'digit:15'], $flags('¿cuántos días me dan si me caso?', 'permiso por matrimonio: 15 días'), 'the standing audit oracle and the crude one agree');
        $this->assertSame([], $flags('me dan 15 días si me caso', 'permiso de 15 días por matrimonio'), 'a figure the employee said is not an addition');
        $this->assertContains('word:grupo', $flags('¿cuánto me pagan?', 'retribución del grupo profesional'));
        // the standing G4 oracle: GeneralLanePostCheck::audit(), minus what the literal itself already carries
        $this->assertContains('audit:spelled_number', $flags('¿cuántos días me dan si me caso?', 'permiso por matrimonio de quince días'));
        $this->assertContains('audit:entitlement_word', $flags('¿cuántos días me dan si me caso?', 'derecho a permiso por matrimonio'));
        $this->assertContains('audit:digit', $flags('¿cuántos días me dan?', 'permiso de 15 días'));
        $this->assertSame([], array_filter($flags('me dan 15 días si me caso', 'permiso de 15 días por matrimonio'), fn ($f) => str_starts_with($f, 'audit:')), 'the literal\'s own digit is not an addition');
        $this->assertContains('name:navarra', $flags('¿cuántos festivos hay?', 'festivos en Navarra'));
        $this->assertSame([], $flags('¿cuántos festivos hay en Navarra?', 'festivos en Navarra'));
    }

    public function test_a_frozen_file_is_refused_when_its_hash_changed_and_a_missing_email_is_skipped(): void
    {
        $file = $this->file([['id' => 'nn-a', 'literal' => '¿me ayudas?', 'over_reach_canonical' => 'x']]);
        file_put_contents(dirname($file).'/MANIFEST.sha256', str_repeat('0', 64)."  neg.json\n");

        $this->artisan('normalization:probe', ['--set' => $file, '--email' => 'probe@example.com'])->expectsOutputToContain('FROZEN FILE CHANGED')->assertExitCode(1);

        $this->planner([]);
        $out = $this->run13b(['--set' => $file, '--allow-unfrozen' => true]);
        $this->assertTrue($out['rows'][0]['skipped'], 'no --email on a negatives file: skipped, never invented');
    }
}
