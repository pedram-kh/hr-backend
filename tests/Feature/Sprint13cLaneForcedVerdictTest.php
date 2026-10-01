<?php

namespace Tests\Feature;

use App\Services\Agent\ToolResult;
use App\Services\Answer\TurnOutcome;
use Tests\TestCase;

/**
 * Slice 13c step 9 — the forced-lane harness's PURE verdict and summary functions (`lane-forced-13c-lib.php` in hr-docs), driven
 * with canned drafts. No network, no model. The property under test: the harness judges a draft by the live locks, in the live
 * order, and never reports a leak as clean.
 */
class Sprint13cLaneForcedVerdictTest extends TestCase
{
    private const CLEAN = 'La excedencia es una situación en la que el contrato de trabajo queda suspendido durante un tiempo, sin que la persona trabajadora preste servicios. Para saber cómo se aplica en tu caso, consulta tu convenio o pregunta a Recursos Humanos.';

    protected function setUp(): void
    {
        parent::setUp();
        $lib = base_path('../hr-docs/sprints/sprint-13c/eval/probes/lane-forced-13c-lib.php');
        if (! is_file($lib)) {
            $this->markTestSkipped('hr-docs is not checked out next to hr-backend');
        }
        require_once $lib;
    }

    /** @param  array<string,mixed>  $lane */
    private function draft(string $answer, string $basis = 'model_knowledge', array $lane = []): ToolResult
    {
        $trace = ['general_lane' => $lane + ['basis' => $basis, 'draft' => ['cost_usd' => 0.004, 'general_knowledge_ms' => 800]]];

        return new ToolResult(ToolResult::TERMINAL, terminalOutcome: new TurnOutcome('answer', $answer, [], $trace, null));
    }

    public function test_a_clean_draft_passes_every_lock(): void
    {
        $v = lane13c_verdict($this->draft(self::CLEAN));

        $this->assertSame('passed_clean', $v['verdict']);
        $this->assertSame('model_knowledge', $v['basis']);
        $this->assertSame(0.004, $v['cost_usd']);
        $this->assertSame(800, $v['latency_ms']);
        $this->assertSame('pass', $v['shape']['verdict']);
        $this->assertNull($v['postcheck']);
    }

    public function test_a_figure_is_blocked_by_the_post_check_before_the_shape_check_runs(): void
    {
        $v = lane13c_verdict($this->draft('La excedencia puede durar hasta 5 años y, en ese caso, el contrato queda suspendido sin trabajar. Consulta tu convenio o pregunta a Recursos Humanos.'));

        $this->assertSame('blocked_postcheck', $v['verdict']);
        $this->assertNotNull($v['postcheck']);
        $this->assertNull($v['shape'], 'the shape check is never reached once the post-check blocks (the live order)');
    }

    public function test_a_named_article_that_the_post_check_lets_through_is_blocked_by_the_shape_check(): void
    {
        $v = lane13c_verdict($this->draft('La excedencia es una suspensión del contrato que aparece recogida en el artículo 46 del Estatuto de los Trabajadores y permite dejar de trabajar un tiempo. Consulta tu convenio o pregunta a Recursos Humanos.'));

        $this->assertSame('blocked_shape', $v['verdict']);
        $this->assertContains('S1', $v['shape']['rule_ids']);
    }

    public function test_a_missing_pointer_and_an_over_long_draft_are_shape_blocks(): void
    {
        $noPointer = lane13c_verdict($this->draft('La excedencia es una situación en la que el contrato de trabajo queda suspendido durante un tiempo.'));
        $this->assertSame('blocked_shape', $noPointer['verdict']);
        $this->assertContains('S3', $noPointer['shape']['rule_ids']);

        $long = lane13c_verdict($this->draft(trim(str_repeat('La excedencia suspende el contrato sin que se trabaje. ', 25)).' Consulta tu convenio o pregunta a Recursos Humanos.'));
        $this->assertSame('blocked_shape', $long['verdict']);
        $this->assertContains('S2', $long['shape']['rule_ids']);
    }

    public function test_a_web_draft_is_not_judged_by_the_model_only_rules(): void
    {
        // An article number is legitimate in a web excerpt (and /ground already checked it); S1 and S3 are model-basis only.
        $v = lane13c_verdict($this->draft('La excedencia está regulada en el artículo de referencia de la página oficial consultada y permite suspender el contrato durante un tiempo.', 'web'));

        $this->assertSame('web', $v['basis']);
        $this->assertSame('passed_clean', $v['verdict']);
    }

    public function test_a_tool_with_no_draft_is_no_material_or_unavailable_never_a_pass(): void
    {
        $this->assertSame('no_material', lane13c_verdict(new ToolResult(ToolResult::NO_MATERIAL, plannerSummary: ['status' => 'no_web_source']))['verdict']);
        $this->assertSame('unavailable', lane13c_verdict(new ToolResult(ToolResult::NO_MATERIAL, plannerSummary: ['status' => 'unavailable']))['verdict']);
    }

    public function test_a_web_draft_that_failed_grounding_is_reported_as_ungrounded(): void
    {
        $trace = ['general_lane' => ['basis' => 'web'], 'floor_decision' => ['note' => 'general lane answer failed grounding against its fetched source']];
        $v = lane13c_verdict(new ToolResult(ToolResult::TERMINAL, terminalOutcome: new TurnOutcome('escalate', 'x', [], $trace, 'low_confidence')));

        $this->assertSame('ungrounded', $v['verdict']);
        $this->assertSame('web', $v['basis']);
    }

    public function test_the_summary_counts_the_block_rate_over_drafts_only(): void
    {
        $row = fn (string $verdict, string $basis, array $extra = []) => $extra + ['class' => 'lane_positive', 'basis' => $basis, 'verdict' => $verdict, 'word_count' => 50, 'cost_usd' => 0.004, 'latency_ms' => 800, 'postcheck' => null, 'shape' => null];
        $s = lane13c_summarise([
            $row('passed_clean', 'model_knowledge'),
            $row('passed_clean', 'model_knowledge'),
            $row('blocked_postcheck', 'model_knowledge', ['postcheck' => ['pattern_id' => 'F1']]),
            $row('blocked_shape', 'model_knowledge', ['shape' => ['rule_ids' => ['S1', 'S3']]]),
            $row('no_material', 'none'),
            $row('ungrounded', 'web'),
        ]);

        $m = $s['lane_positive/model_knowledge'];
        $this->assertSame(4, $m['drafts']);
        $this->assertSame(0.5, $m['block_rate']);
        $this->assertSame(['F1' => 1], $m['postcheck_rule_ids']);
        $this->assertSame(['S1' => 1, 'S3' => 1], $m['shape_rule_ids']);
        $this->assertSame(0, $m['audit_bypass']);

        $all = $s['lane_positive/all'];
        $this->assertSame(6, $all['rows']);
        $this->assertSame(4, $all['drafts'], 'no_material / ungrounded rows never reached the deterministic locks');
        $this->assertSame(1, $all['no_material']);
        $this->assertSame(1, $all['ungrounded']);
    }

    public function test_locks_are_run_independently_and_the_sole_catcher_is_named(): void
    {
        // E2 alone ("garantizar"): post-check blocks, shape passes → E2 is the sole catcher.
        $e2 = lane13c_verdict($this->draft('La Seguridad Social es el sistema público que busca garantizar una red de protección para las personas. Para saber cómo se aplica en tu caso, consulta tu convenio o pregunta a Recursos Humanos.'));
        $this->assertSame('blocked_postcheck', $e2['verdict']);
        $this->assertSame(['E2'], $e2['locks']['postcheck_ids']);
        $this->assertSame([], $e2['locks']['shape_ids']);
        $this->assertSame('E2', $e2['locks']['sole_catcher']);

        // A figure AND a missing pointer: two locks would have stopped it → no sole catcher, and the shape check still ran.
        $two = lane13c_verdict($this->draft('La excedencia puede durar hasta 5 años y suspende el contrato.'));
        $this->assertSame('blocked_postcheck', $two['verdict']);
        $this->assertNull($two['shape'], 'the live verdict still short-circuits');
        $this->assertContains('S3', $two['locks']['shape_ids'], 'but the independent lock record does not');
        $this->assertNull($two['locks']['sole_catcher']);

        // A clean draft has none.
        $clean = lane13c_verdict($this->draft(self::CLEAN));
        $this->assertSame([], $clean['locks']['postcheck_ids']);
        $this->assertNull($clean['locks']['sole_catcher']);
    }

    public function test_a_fallback_answer_adds_the_abandoned_web_attempts_cost(): void
    {
        $v = lane13c_verdict($this->draft(self::CLEAN, 'model_knowledge', ['fallback' => ['from' => 'web', 'reason' => 'web_empty_draft', 'web_cost_usd' => 0.013]]));

        $this->assertSame(0.017, $v['cost_usd']);
        $this->assertSame('web_empty_draft', $v['fallback']['reason']);
    }

    public function test_the_percentile_is_nearest_rank_and_null_when_empty(): void
    {
        $this->assertNull(lane13c_percentile([], 50));
        $this->assertSame(3, lane13c_percentile([5, 1, 3, 2, 4], 50));
        $this->assertSame(5, lane13c_percentile([5, 1, 3, 2, 4], 95));
    }
}
