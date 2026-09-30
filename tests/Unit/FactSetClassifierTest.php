<?php

namespace Tests\Unit;

use App\Models\ReferenceFact;
use App\Services\Answer\ReferenceFactPath;
use App\Support\FactSetClassifier;
use PHPUnit\Framework\TestCase;

/**
 * Slice 13d (ADR-0037) — U1–U9. The rule that decides whether several verified
 * facts that tie on one topic are answered TOGETHER or the tie escalates.
 * Pure: no database. The default of every branch it cannot PROVE is "escalate".
 */
class FactSetClassifierTest extends TestCase
{
    /** Convenio 20's real facts 140 / 143 (staging), `raw_values` as stored. */
    private function fact140(): ReferenceFact
    {
        return $this->fact(140, 'Con carácter general: Año 2025: 1704 horas de trabajo efectivo; Año 2026: 1700 horas; Año 2027: 1696 horas; Año 2028: 1692 horas.',
            ['2025' => '1704 horas', '2026' => '1700 horas', '2027' => '1696 horas', '2028' => '1692 horas']);
    }

    private function fact143(): ReferenceFact
    {
        return $this->fact(143, 'Reglas generales de jornada: en jornadas continuadas de más de 6 horas un descanso de 15 minutos; 2 días de libre disposición.',
            ['jornada_irregular' => '0%', 'computo_tiempo_trabajo' => 'en el puesto', 'dias_libre_disposicion' => '2 días', 'descanso_jornada_continuada' => '15 minutos']);
    }

    /** @param  array<mixed>|null  $raw */
    private function fact(int $id, string $value, ?array $raw, array $extra = []): ReferenceFact
    {
        $f = new ReferenceFact(['value' => $value, 'raw_values' => $raw] + $extra);
        $f->id = $id;

        return $f;
    }

    // U1
    public function test_u1_the_real_140_143_raw_values_are_complementary(): void
    {
        $rel = FactSetClassifier::relate($this->fact140(), $this->fact143());

        $this->assertSame('complementary', $rel['relation']);
        $this->assertSame('disjoint_quantity_keys', $rel['reason']);
        $this->assertSame([], $rel['shared_keys']);
        $this->assertSame('complementary', FactSetClassifier::classifySet([$this->fact140(), $this->fact143()])['composition']);
    }

    // U2
    public function test_u2_a_shared_quantity_key_is_contradictory_and_names_the_key(): void
    {
        $a = $this->fact(1, '1704 horas', ['horas_anuales' => 1704, 'extra' => 1]);
        $b = $this->fact(2, '1720 horas', ['Horas Anuales' => 1720]); // normalises to the same key

        $rel = FactSetClassifier::relate($a, $b);

        $this->assertSame('contradictory', $rel['relation']);
        $this->assertSame('same_quantity', $rel['reason']);
        $this->assertSame(['horas_anuales'], $rel['shared_keys']);
    }

    // U3
    public function test_u3_null_empty_or_list_raw_values_can_never_vouch_for_complementarity(): void
    {
        $named = $this->fact(2, 'b', ['jornada_irregular' => '0%']);

        foreach ([null, [], ['a', 'b'], [0 => 'x', 1 => 'y']] as $shape) {
            $rel = FactSetClassifier::relate($this->fact(1, 'a', $shape), $named);
            $this->assertSame('contradictory', $rel['relation'], 'shape: '.json_encode($shape));
            $this->assertSame('no_quantity_keys', $rel['reason']);
        }
        $this->assertNull(FactSetClassifier::quantityKeys($this->fact(1, 'a', ['   ' => 1, '!!!' => 2])), 'keys that normalise to nothing name no quantity');
    }

    // U4
    public function test_u4_an_unresolved_duplicate_flag_is_contradictory_despite_disjoint_keys(): void
    {
        $a = $this->fact(1, 'a', ['x' => 1]);
        $b = $this->fact(2, 'b', ['y' => 2], ['duplicate_of_id' => 1]);

        $this->assertSame('flagged_duplicate_unresolved', FactSetClassifier::relate($a, $b)['reason']);
        $this->assertSame('flagged_duplicate_unresolved', FactSetClassifier::relate($b, $a)['reason'], 'symmetric');

        // A pair a human already RESOLVED is judged on its keys alone ("coexists" does not force conflict,
        // and does not force composition either — the keys decide).
        $resolved = $this->fact(2, 'b', ['y' => 2], ['duplicate_of_id' => 1, 'resolution' => 'coexists']);
        $this->assertSame('complementary', FactSetClassifier::relate($a, $resolved)['relation']);
        $overlapping = $this->fact(2, 'b', ['x' => 2], ['duplicate_of_id' => 1, 'resolution' => 'coexists']);
        $this->assertSame('contradictory', FactSetClassifier::relate($a, $overlapping)['relation']);
    }

    // U5
    public function test_u5_one_bad_pair_escalates_the_whole_cohort_and_names_it(): void
    {
        $a = $this->fact(1, 'a', ['x' => 1]);
        $b = $this->fact(2, 'b', ['y' => 2]);
        $c = $this->fact(3, 'c', ['y' => 3, 'z' => 1]); // shares `y` with b only

        $verdict = FactSetClassifier::classifySet([$a, $b, $c]);

        $this->assertSame('conflict', $verdict['composition']);
        $this->assertSame([1, 2, 3], $verdict['cohort_ids']);
        $bad = array_values(array_filter($verdict['pairs'], fn ($p) => $p['relation'] === 'contradictory'));
        $this->assertCount(1, $bad);
        $this->assertSame([2, 3], [$bad[0]['a'], $bad[0]['b']]);
        $this->assertSame(['y'], $bad[0]['shared_keys']);
        $this->assertCount(3, $verdict['pairs'], 'every pair evaluated');
    }

    // U6
    public function test_u6_key_normalisation(): void
    {
        $this->assertSame('descanso_jornada_continuada', FactSetClassifier::normaliseKey('Descanso Jornada-Continuada'));
        $this->assertSame('dias_libre_disposicion', FactSetClassifier::normaliseKey('  Días libre disposición '));
        $this->assertSame('ano_2025', FactSetClassifier::normaliseKey('Año 2025'));
        $this->assertSame(['2025'], FactSetClassifier::quantityKeys($this->fact(1, 'a', ['2025' => 1])), 'numeric keys stay strings');
    }

    // U7
    public function test_u7_ordering_is_deterministic_under_shuffled_input(): void
    {
        $facts = [
            $this->fact(5, 'sin cifras, texto largo largo largo', ['a' => 1]),
            $this->fact143(),
            $this->fact140(),
            $this->fact(4, 'sin cifras', ['b' => 1]),
            $this->fact(3, 'sin cifras', ['c' => 1]),
        ];
        // figures desc (140: 4, 143: 2), then shorter value, then lower id.
        $expected = [140, 143, 3, 4, 5];

        mt_srand(13);
        for ($i = 0; $i < 25; $i++) {
            shuffle($facts);
            $this->assertSame($expected, array_map(fn ($f) => $f->id, FactSetClassifier::order($facts)));
        }
    }

    // U8
    public function test_u8_the_cap_keeps_three_and_a_contradictory_fourth_still_escalates(): void
    {
        $four = array_map(fn ($i) => $this->fact($i, "dato $i", ["q$i" => $i]), [1, 2, 3, 4]);
        $this->assertSame('complementary', FactSetClassifier::classifySet($four)['composition']);
        $this->assertSame(3, FactSetClassifier::MAX_SET);

        $four[3] = $this->fact(4, 'dato 4', ['q1' => 9]); // clashes with fact 1
        $this->assertSame('conflict', FactSetClassifier::classifySet($four)['composition'], 'classification runs over ALL members before any cap');
    }

    // U9
    public function test_u9_two_year_keyed_schedules_that_both_contain_2025_are_contradictory(): void
    {
        $a = $this->fact(1, 'a', ['2025' => '1704 horas', '2026' => '1700 horas']);
        $b = $this->fact(2, 'b', ['2025' => '1720 horas', '2027' => '1700 horas']);

        $rel = FactSetClassifier::relate($a, $b);
        $this->assertSame('contradictory', $rel['relation']);
        $this->assertSame(['2025'], $rel['shared_keys']);
    }

    public function test_distinct_by_value_collapses_byte_identical_duplicates_to_the_lowest_id(): void
    {
        $out = FactSetClassifier::distinctByValue([
            $this->fact(9, 'igual', ['a' => 1]), $this->fact(2, 'igual', ['b' => 1]), $this->fact(5, 'otro', ['c' => 1]),
        ]);

        $this->assertSame([2, 5], array_map(fn ($f) => $f->id, $out));
    }

    /** `figureCount` mirrors `ReferenceFactPath::extractFiguresByUnit` — they must never drift. */
    public function test_figure_count_matches_the_figure_guards_own_extractor(): void
    {
        $path = (new \ReflectionClass(ReferenceFactPath::class))->newInstanceWithoutConstructor();
        $extract = new \ReflectionMethod($path, 'extractFiguresByUnit');

        $texts = [
            $this->fact140()->value, $this->fact143()->value,
            '90 días, 75 días (temporales > 3 meses), 60 días; 1.234,56 euros y 1.234 horas; 2 semanas; 1 año',
            'sin cifras ni unidades', '', '6 Horas y 6 horas y 6 HORAS, 15 minutos, 3 años, 3 anos',
        ];
        foreach ($texts as $text) {
            $expected = array_sum(array_map('count', $extract->invoke($path, $text)));
            $this->assertSame($expected, FactSetClassifier::figureCount($text), $text);
        }
    }
}
