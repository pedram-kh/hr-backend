<?php

namespace Tests\Feature;

use App\Services\Agent\Rules\GeneralLanePostCheck;
use App\Services\Agent\Rules\ModelKnowledgeShapeCheck;
use App\Services\Agent\Rules\ModelKnowledgeShapePostCallRule;
use App\Services\Agent\ToolResult;
use App\Services\Agent\TurnState;
use App\Services\Agent\Verdict;
use App\Services\Answer\TurnOutcome;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Slice 13c (plan.md §2.4): S1 citation / S2 length / S3 closing pointer, and the post-call rule around them. */
class ModelKnowledgeShapeCheckTest extends TestCase
{
    private const POINTER = 'Para saber cómo se aplica en tu caso, consulta tu convenio o pregunta a Recursos Humanos.';

    private function good(): string
    {
        return 'La excedencia es una situación en la que la relación laboral queda suspendida durante un tiempo, sin que la persona trabajadora preste servicios ni cobre salario. '.self::POINTER;
    }

    public function test_a_clean_model_draft_passes(): void
    {
        $r = ModelKnowledgeShapeCheck::check($this->good(), 'model_knowledge');
        $this->assertSame('pass', $r['verdict']);
        $this->assertSame([], $r['rule_ids']);
    }

    /**
     * The citations the existing post-check LETS THROUGH (it strips legal-citation tokens before F1): proof S1 is needed.
     *
     * @return array<string,array{string}>
     */
    public static function citations(): array
    {
        return [
            'article' => ['Esto está regulado en el artículo 46 del Estatuto de los Trabajadores.'],
            'art dot' => ['Se recoge en el art. 47 ET y más normas.'],
            'real decreto' => ['Lo desarrolla el Real Decreto-ley 8/2019 de forma general.'],
            'ley organica' => ['Se regula en la Ley Orgánica 3/2007 sobre igualdad.'],
            'ley de' => ['Figura en la Ley de Prevención de Riesgos Laborales.'],
            'boe' => ['Se publicó en el BOE hace tiempo.'],
            'sentencia' => ['Así lo ha dicho una sentencia del Tribunal Supremo.'],
            'url' => ['Más información en https://www.boe.es/buscar.'],
            'www' => ['Puedes verlo en www.seg-social.es.'],
            'fuente' => ['Es un permiso retribuido. [Fuente: BOE]'],
            'segun la ley' => ['Según la ley, este trámite se hace de forma general.'],
        ];
    }

    #[DataProvider('citations')]
    public function test_s1_blocks_a_citation_the_post_check_lets_through(string $sentence): void
    {
        $text = $sentence.' '.self::POINTER;
        $this->assertNull(GeneralLanePostCheck::scan($text), 'premise: the existing post-check passes this (if it blocks, S1 is moot for this case)');
        $r = ModelKnowledgeShapeCheck::check($text, 'model_knowledge');
        $this->assertSame('blocked', $r['verdict']);
        $this->assertContains('S1', $r['rule_ids']);
    }

    public function test_s1_does_not_apply_to_a_web_basis_draft(): void
    {
        $text = 'Regulado en el artículo 34 según la página oficial. '.self::POINTER;
        $this->assertSame('pass', ModelKnowledgeShapeCheck::check($text, 'web')['verdict']);
        $this->assertContains('S1', ModelKnowledgeShapeCheck::check($text, 'model_knowledge')['rule_ids']);
    }

    public function test_s2_counts_words_and_applies_to_both_bases(): void
    {
        $filler = trim(str_repeat('palabra ', 118));
        $ok = $filler.' convenio.';   // 119 words
        $this->assertSame(119, ModelKnowledgeShapeCheck::wordCount($ok));
        $this->assertSame('pass', ModelKnowledgeShapeCheck::check($ok, 'model_knowledge')['verdict']);
        $edge = $filler.' convenio también.'; // 120: still ok
        $this->assertSame('pass', ModelKnowledgeShapeCheck::check($edge, 'model_knowledge')['verdict']);
        $over = $filler.' uno dos convenio.'; // 121
        foreach (['model_knowledge', 'web'] as $basis) {
            $r = ModelKnowledgeShapeCheck::check($over, $basis);
            $this->assertSame('blocked', $r['verdict'], $basis);
            $this->assertContains('S2', $r['rule_ids']);
        }
    }

    public function test_s3_needs_the_pointer_in_the_last_sentence_not_just_somewhere(): void
    {
        $early = 'Consulta tu convenio para el detalle. La excedencia suspende la relación laboral durante un periodo.';
        $r = ModelKnowledgeShapeCheck::check($early, 'model_knowledge');
        $this->assertSame(['S3'], $r['rule_ids']);

        foreach (['consulta tu convenio.', 'consulta con Recursos Humanos.', 'pregúntalo en RR. HH.', 'pregúntalo a RRHH', 'Consulta tu Convenio Colectivo o a RR.HH.'] as $tail) {
            $this->assertSame('pass', ModelKnowledgeShapeCheck::check('Es una situación de suspensión del contrato. '.$tail, 'model_knowledge')['verdict'], $tail);
        }
    }

    public function test_s3_does_not_apply_to_a_web_basis_draft(): void
    {
        $this->assertSame('pass', ModelKnowledgeShapeCheck::check('Definición sin cierre.', 'web')['verdict']);
        $this->assertSame(['S3'], ModelKnowledgeShapeCheck::check('Definición sin cierre.', 'model_knowledge')['rule_ids']);
    }

    public function test_every_s1_pattern_is_exercised(): void
    {
        $this->assertCount(8, ModelKnowledgeShapeCheck::s1Names());
    }

    public function test_the_mandated_pointer_and_both_caveat_styles_do_not_trip_the_existing_post_check(): void
    {
        $this->assertNull(GeneralLanePostCheck::scan(self::POINTER));
        $this->assertNull(GeneralLanePostCheck::scan($this->good()));
    }

    private function toolResult(string $answer, ?string $basis): ToolResult
    {
        $trace = ['general_lane' => array_filter(['basis' => $basis, 'sources' => [['kind' => 'model_knowledge']]], fn ($v) => $v !== null)];
        $outcome = new TurnOutcome('answer', $answer, [], $trace, null);

        return new ToolResult(ToolResult::TERMINAL, terminalOutcome: $outcome, plannerSummary: ['status' => 'answer']);
    }

    public function test_the_rule_force_escalates_general_lane_blocked_and_keeps_the_lane_trace(): void
    {
        $rule = new ModelKnowledgeShapePostCallRule;
        $state = (new \ReflectionClass(TurnState::class))->newInstanceWithoutConstructor(); // the rule never reads it

        $v = $rule->evaluate($state, null, $this->toolResult('Regulado en el artículo 46. '.self::POINTER, 'model_knowledge'));
        $this->assertTrue($v->isTerminal());
        $this->assertSame(Verdict::FORCE_ESCALATE, $v->forceType);
        $this->assertSame('general_lane_shape_check', $v->rule);
        $this->assertSame('general_lane_blocked', $v->forcePayload->escalationReason);
        $lane = $v->forcePayload->trace['general_lane'];
        $this->assertSame('model_knowledge', $lane['basis']);
        $this->assertSame(['S1'], $lane['shape']['rule_ids']);
        $this->assertSame('escalate', $v->forcePayload->trace['floor_decision']['outcome']);

        $this->assertSame(Verdict::ALLOW, $rule->evaluate($state, null, $this->toolResult($this->good(), 'model_knowledge'))->status);
        // a missing basis is treated as the stricter-than-nothing web reading (S2 only) — never as "skip the check"
        $this->assertTrue($rule->evaluate($state, null, $this->toolResult(trim(str_repeat('a ', 130)), null))->isTerminal());
    }

    public function test_the_rule_ignores_non_answers_and_missing_results(): void
    {
        $rule = new ModelKnowledgeShapePostCallRule;
        $state = (new \ReflectionClass(TurnState::class))->newInstanceWithoutConstructor(); // the rule never reads it
        $this->assertSame(Verdict::ALLOW, $rule->evaluate($state, null, null)->status);
        $esc = new ToolResult(ToolResult::TERMINAL, terminalOutcome: new TurnOutcome('escalate', 'x', [], [], 'low_confidence'));
        $this->assertSame(Verdict::ALLOW, $rule->evaluate($state, null, $esc)->status);
    }
}
