<?php

namespace Tests\Feature;

use App\Console\Commands\AnswerGate;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Slice 13d — `answer:gate` scores `expect.fact_set` ({facts_selected, cited_min, cited_all}) from the turn trace,
 * wherever the engine nested it. Pure scoring: no DB, no model.
 */
class Sprint13dGateFactSetScoringTest extends TestCase
{
    /** @param array<string,mixed> $expectFactSet */
    private function score(array $trace, array $expectFactSet, string $path = 'reference_fact_composition'): array
    {
        $gate = app(AnswerGate::class);
        $m = new ReflectionMethod($gate, 'scoreCase');
        $m->setAccessible(true);
        $trace['floor_decision'] = ['path' => $path, 'authority_used' => ['structured_reference']];

        return $m->invoke($gate, [
            'id' => 'x', 'question' => 'q', 'expect' => ['outcome' => 'answer', 'path' => ['reference_fact', 'reference_fact_composition'], 'fact_set' => $expectFactSet],
        ], 'classic', ['outcome' => 'answer', 'answer' => 'respuesta', 'escalation_reason' => null], $trace, null);
    }

    private function classicTrace(array $selected, array $offered, array $cited): array
    {
        return [
            'reference_fact' => ['fact_set' => ['composition' => 'complementary', 'facts_selected' => $selected], 'validity_selection' => 'same_validity_complementary'],
            'composition' => ['detected' => true, 'fact_ids_offered' => $offered, 'fact_ids_cited' => $cited],
        ];
    }

    public function test_both_cited_passes_every_expectation(): void
    {
        $row = $this->score($this->classicTrace([140, 143], [140, 143], [143, 140]), ['facts_selected' => [143, 140], 'cited_min' => 1, 'cited_all' => true]);

        $this->assertTrue($row['pass']);
        $this->assertTrue($row['fact_set_ok']);
        $this->assertSame([140, 143], $row['fact_trace']['fact_ids_offered']);
        $this->assertSame('same_validity_complementary', $row['fact_trace']['validity_selection']);
    }

    public function test_one_cited_passes_cited_min_but_fails_cited_all(): void
    {
        $trace = $this->classicTrace([140, 143], [140, 143], [140]);

        $this->assertTrue($this->score($trace, ['facts_selected' => [140, 143], 'cited_min' => 1])['pass']);
        $this->assertFalse($this->score($trace, ['facts_selected' => [140, 143], 'cited_all' => true])['pass']);
    }

    public function test_zero_cited_fails_cited_min(): void
    {
        $this->assertFalse($this->score($this->classicTrace([140, 143], [140, 143], []), ['cited_min' => 1])['pass']);
    }

    public function test_a_cited_id_outside_the_offered_set_fails_even_without_cited_min(): void
    {
        $this->assertFalse($this->score($this->classicTrace([140, 143], [140, 143], [140, 999]), ['facts_selected' => [140, 143]])['pass']);
    }

    public function test_a_wrong_selected_set_or_a_missing_trace_fails(): void
    {
        $this->assertFalse($this->score($this->classicTrace([140], [], []), ['facts_selected' => [140, 143]])['pass']);
        $this->assertFalse($this->score([], ['facts_selected' => [140, 143]])['pass']);
    }

    public function test_phase_one_quote_has_no_composition_so_only_the_selected_set_is_judged(): void
    {
        $trace = ['reference_fact' => ['fact_set' => ['composition' => 'complementary', 'facts_selected' => [140, 143]]]];

        $this->assertTrue($this->score($trace, ['facts_selected' => [140, 143], 'cited_min' => 1, 'cited_all' => true], 'reference_fact')['pass']);
    }

    public function test_the_trace_is_found_when_the_agent_nests_it_inside_a_tool_step(): void
    {
        $nested = ['agent' => ['steps' => [['type' => 'tool', 'result' => $this->classicTrace([140, 143], [140, 143], [140, 143])]]]];

        $this->assertTrue($this->score($nested, ['facts_selected' => [140, 143], 'cited_all' => true])['pass']);
    }

    public function test_absent_passes_only_when_no_set_was_formed_or_offered(): void
    {
        $this->assertTrue($this->score([], ['absent' => true])['pass']);
        $this->assertTrue($this->score(['reference_fact' => ['fact_id' => 5, 'match_kind' => 'group']], ['absent' => true])['pass']);
        $this->assertFalse($this->score($this->classicTrace([140, 143], [140, 143], [140]), ['absent' => true])['pass']);
    }

    public function test_selected_count_is_judged_without_knowing_the_ids(): void
    {
        $trace = $this->classicTrace([7, 9], [7, 9], [7, 9]);

        $this->assertTrue($this->score($trace, ['selected_count' => 2, 'cited_all' => true])['pass']);
        $this->assertFalse($this->score($trace, ['selected_count' => 3])['pass']);
    }

    public function test_value_not_contains_fails_a_row_whose_answer_carries_the_forbidden_needle(): void
    {
        $gate = app(AnswerGate::class);
        $m = new ReflectionMethod($gate, 'scoreCase');
        $m->setAccessible(true);
        $case = ['id' => 'x', 'question' => 'q', 'expect' => ['outcome' => 'answer', 'value_contains' => ['Gamma'], 'value_not_contains' => ['Alfa']]];

        $this->assertTrue($m->invoke($gate, $case, 'classic', ['outcome' => 'answer', 'answer' => 'Gamma'], [], null)['pass']);
        $this->assertFalse($m->invoke($gate, $case, 'classic', ['outcome' => 'answer', 'answer' => 'Gamma y Alfa'], [], null)['pass']);
    }

    public function test_a_row_without_the_expectation_is_unchanged(): void
    {
        $gate = app(AnswerGate::class);
        $m = new ReflectionMethod($gate, 'scoreCase');
        $m->setAccessible(true);
        $row = $m->invoke($gate, ['id' => 'x', 'question' => 'q', 'expect' => ['outcome' => 'answer']], 'classic', ['outcome' => 'answer', 'answer' => 'a'], [], null);

        $this->assertTrue($row['pass']);
        $this->assertNull($row['fact_set_ok']);
        $this->assertNull($row['fact_trace']);
    }
}
