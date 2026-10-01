<?php

namespace Tests\Feature;

use App\Services\Agent\Rules\GeneralLanePostCheck;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Slice 13c, build step 1 (plan.md §4) — pre-screen v2 (`GeneralLanePostCheck::questionAdmitsLane`) against the FROZEN
 * fixtures (`tests/fixtures/sprint13c/*` = `hr-docs/sprints/sprint-13c/eval/*`, sha256 in its MANIFEST).
 *
 * No DB: static regex, like `GeneralLanePostCheckTest`. The spec's gate (§5.4): ≥ 95 % entitlement recall, false-explanatory
 * rate REPORTED. This test is stricter on purpose (every entitlement/adversarial/colloquial fixture refused, every minimal
 * pair separated), so a loosening is a deliberate diff, 13b precedent.
 */
class Sprint13cPrescreenFixturesTest extends TestCase
{
    /** @return list<array<string,mixed>> */
    private static function cases(string $file): array
    {
        $raw = file_get_contents(__DIR__.'/../fixtures/sprint13c/'.$file);

        return json_decode((string) $raw, true, 512, JSON_THROW_ON_ERROR)['cases'];
    }

    public function test_frozen_fixtures_match_the_docs_manifest_when_hr_docs_is_checked_out(): void
    {
        $eval = base_path('../hr-docs/sprints/sprint-13c/eval');
        if (! is_file($eval.'/MANIFEST.sha256')) {
            $this->markTestSkipped('hr-docs is not checked out next to hr-backend.');
        }
        foreach (['prescreen-fixtures.json', 'lane-colloquial-negatives.json'] as $f) {
            $this->assertSame(hash_file('sha256', $eval.'/'.$f), hash_file('sha256', __DIR__.'/../fixtures/sprint13c/'.$f), "$f drifted from the frozen copy");
            $this->assertStringContainsString(hash_file('sha256', $eval.'/'.$f).'  '.$f, (string) file_get_contents($eval.'/MANIFEST.sha256'), "$f is not the frozen version");
        }
    }

    public function test_fixture_shape_counts(): void
    {
        $fx = self::cases('prescreen-fixtures.json');
        $by = fn (string $c) => array_values(array_filter($fx, fn ($x) => $x['class'] === $c));
        $this->assertCount(34, $by('entitlement'));
        $this->assertCount(32, $by('explanatory'));
        $this->assertCount(10, $by('adversarial'));
        $pairs = [];
        foreach ($fx as $c) {
            if ($c['pair']) {
                $pairs[$c['pair']][] = $c['class'];
            }
        }
        $this->assertCount(13, $pairs);
        foreach ($pairs as $classes) {
            $this->assertEqualsCanonicalizing(['entitlement', 'explanatory'], $classes);
        }
        $this->assertCount(18, self::cases('lane-colloquial-negatives.json'));
    }

    public function test_every_entitlement_adversarial_and_colloquial_fixture_is_refused(): void
    {
        $leaks = [];
        foreach (self::cases('prescreen-fixtures.json') as $c) {
            if ($c['expected'] === 'entitlement' && GeneralLanePostCheck::questionAdmitsLane($c['question'])) {
                $leaks[] = $c['id'].' '.$c['question'];
            }
        }
        foreach (self::cases('lane-colloquial-negatives.json') as $c) {
            if (GeneralLanePostCheck::questionAdmitsLane($c['question'])) {
                $leaks[] = $c['id'].' '.$c['question'];
            }
        }
        $this->assertSame([], $leaks, "pre-screen v2 admitted:\n".implode("\n", $leaks));
    }

    /**
     * Freeze note §4: ADV-05/08 drove the `obligation` rule (contaminated); ADV-09/10 are the clean held-out pair and the
     * ONLY adversarial gate number. Reported separately so a pass on the contaminated two can never stand in for it.
     */
    public function test_adversarial_clean_heldout_pair_is_reported_separately_from_the_contaminated_two(): void
    {
        $adv = array_values(array_filter(self::cases('prescreen-fixtures.json'), fn ($x) => $x['class'] === 'adversarial'));
        $role = array_column($adv, 'gate_role', 'id');
        $this->assertSame('clean_heldout', $role['ADV-09']);
        $this->assertSame('clean_heldout', $role['ADV-10']);
        $this->assertSame('contaminated_drove_obligation_rule', $role['ADV-05']);
        $this->assertSame('contaminated_drove_obligation_rule', $role['ADV-08']);
        $this->assertCount(2, array_keys($role, 'clean_heldout', true));
        $this->assertCount(2, array_keys($role, 'contaminated_drove_obligation_rule', true));

        $admitted = fn (string $r) => array_values(array_filter($adv, fn ($x) => $x['gate_role'] === $r && GeneralLanePostCheck::questionAdmitsLane($x['question'])));
        $this->assertSame([], array_column($admitted('clean_heldout'), 'id'), 'clean held-out adversarial pair leaked (the gate number)');
        $this->assertSame([], array_column($admitted('contaminated_drove_obligation_rule'), 'id'), 'contaminated pair leaked (reported, not the gate number)');
        $this->assertSame([], array_column($admitted('visible_at_tuning'), 'id'));
    }

    public function test_entitlement_recall_is_at_least_95_percent_on_the_held_out_half_and_overall(): void
    {
        foreach (['heldout', 'all'] as $half) {
            $ent = array_filter(self::cases('prescreen-fixtures.json'), fn ($c) => $c['class'] === 'entitlement' && ($half === 'all' || $c['half'] === $half));
            $refused = count(array_filter($ent, fn ($c) => ! GeneralLanePostCheck::questionAdmitsLane($c['question'])));
            $this->assertGreaterThanOrEqual(0.95, $refused / count($ent), "entitlement recall on $half");
        }
    }

    public function test_every_minimal_pair_is_separated(): void
    {
        $pairs = [];
        foreach (self::cases('prescreen-fixtures.json') as $c) {
            if ($c['pair']) {
                $pairs[$c['pair']][$c['class']] = $c['question'];
            }
        }
        foreach ($pairs as $pair => $m) {
            $this->assertFalse(GeneralLanePostCheck::questionAdmitsLane($m['entitlement']), "$pair entitlement side admitted: {$m['entitlement']}");
            $this->assertTrue(GeneralLanePostCheck::questionAdmitsLane($m['explanatory']), "$pair explanatory side refused: {$m['explanatory']} ({$this->why($m['explanatory'])})");
        }
    }

    public function test_the_only_refused_explanatory_fixtures_are_the_three_documented_fail_closed_ones(): void
    {
        $refused = [];
        $admitted = 0;
        foreach (self::cases('prescreen-fixtures.json') as $c) {
            if ($c['class'] !== 'explanatory') {
                continue;
            }
            GeneralLanePostCheck::questionAdmitsLane($c['question']) ? $admitted++ : $refused[] = $c['id'];
        }
        sort($refused);
        // Procedural ("cómo se pide"), first-person ("mi contrato") and borderline ("quién paga") — refused on purpose (plan Q4).
        $this->assertSame(['EXP-X14', 'EXP-X15', 'EXP-X19'], $refused);
        $this->assertSame(29, $admitted);
        fwrite(STDERR, sprintf("\n[13c pre-screen v2] false-explanatory (explanatory refused): %d/32 = %.1f%%\n", count($refused), 100 * count($refused) / 32));
    }

    public function test_v2_never_admits_a_question_v1_denies_and_the_whole_pool_clears_it(): void
    {
        $all = array_merge(self::cases('prescreen-fixtures.json'), self::cases('lane-colloquial-negatives.json'));
        foreach ($all as $c) {
            if (GeneralLanePostCheck::questionPrescreenHit($c['question'])) {
                $this->assertFalse(GeneralLanePostCheck::questionAdmitsLane($c['question']), 'v2 must be monotone: '.$c['question']);
            }
        }
        $pool = json_decode((string) file_get_contents(__DIR__.'/../../../hr-docs/sprints/sprint-13c/eval/lane-positives-pool.json'), true);
        if (is_array($pool)) {
            foreach ($pool['cases'] as $c) {
                $this->assertTrue(GeneralLanePostCheck::questionAdmitsLane($c['question']), $c['id'].' refused: '.$this->why($c['question']));
            }
        }
    }

    public static function refusalReasons(): array
    {
        return [
            'v1 cuánto' => ['¿Cuántos días de vacaciones hay?', 'prescreen_v1'],
            'figure concept SMI' => ['¿Qué es el SMI?', 'figure_concept'],
            'figure concept IPREM' => ['¿Qué es el IPREM?', 'figure_concept'],
            'figure concept base reguladora' => ['¿Qué es la base reguladora?', 'figure_concept'],
            'figure concept base de cotización' => ['¿Qué es la base de cotización?', 'figure_concept'],
            'figure concept jornada máxima' => ['¿Qué es la jornada máxima legal?', 'figure_concept'],
            'figure concept periodo de carencia' => ['¿Qué es el periodo de carencia?', 'figure_concept'],
            'first person wrapper' => ['¿Qué es el periodo de prueba en mi contrato?', 'first_person'],
            'first person puedo' => ['¿Qué es la excedencia que puedo coger?', 'first_person'],
            'obligation wrapper' => ['¿Qué es lo que te toca de vacaciones por ley?', 'obligation'],
            'obligation debe' => ['¿Qué es el preaviso y cuándo se debe dar?', 'obligation'],
            'obligation tener derecho' => ['¿Qué significa tener derecho a excedencia?', 'obligation'],
            'shape procedural' => ['¿Cómo se pide una excedencia?', 'shape'],
            'shape statement' => ['Explícame la excedencia.', 'shape'],
            'admitted definition' => ['¿Qué es una excedencia?', null],
            'admitted how-it-works' => ['¿Cómo funciona una mutua cuando estás de baja?', null],
            'admitted difference' => ['¿Qué diferencia hay entre una baja y una excedencia?', null],
            'admitted purpose' => ['¿Para qué sirve el registro de jornada?', null],
            'admitted consists' => ['¿En qué consiste la jornada irregular?', null],
            'admitted uppercase and accents' => ['¿QUÉ ES UN CONTRATO FIJO DISCONTINUO?', null],
            'admitted nbsp' => ["¿Qué\u{00A0}es la vida laboral?", null],
        ];
    }

    #[DataProvider('refusalReasons')]
    public function test_refusal_reason_ids(string $question, ?string $expected): void
    {
        $this->assertSame($expected, GeneralLanePostCheck::questionRefusal($question));
        $this->assertSame($expected === null, GeneralLanePostCheck::questionAdmitsLane($question));
    }

    private function why(string $q): string
    {
        return (string) (GeneralLanePostCheck::questionRefusal($q) ?? 'admitted');
    }
}
