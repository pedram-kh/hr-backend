<?php

namespace Tests\Feature;

use App\Services\Agent\Rules\GeneralLanePostCheck;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Sprint 13, build step 9 (plan.md §B.6.3) — the deterministic figure/
 * entitlement post-check on a `general_knowledge`-lane answer. No DB, no
 * `RefreshDatabase`: `scan()`/`questionPrescreenHit()` are plain static
 * methods over regex, exactly the point of the class's own docblock.
 *
 * Three requirements enforced here, per the build plan's own test list:
 *  1. ≥60 must-BLOCK strings, ≥3 per pattern id, including accented/
 *     uppercase/NBSP/spelled-out variants.
 *  2. ≥15 must-PASS definitions (clean, figure-free, entitlement-free prose).
 *  3. A COVERAGE GUARD: one fixture per pattern id that ONLY that pattern
 *     catches — deleting any pattern must fail THIS test, not just reduce
 *     block coverage silently.
 */
class GeneralLanePostCheckTest extends TestCase
{
    // ---- 1. Must-block, grouped by the pattern each is aimed at ---------

    public static function mustBlockProvider(): array
    {
        $cases = [
            // F1 — any remaining digit (after legal-citation tokens stripped).
            'F1 bare digit' => ['El documento lo describe en la sección 7 del manual interno.'],
            'F1 digit with accented word nearby' => ['Aparece un código de referencia 42 en el ejemplo.'],
            'F1 uppercase context' => ['EL EJEMPLO 3 SE DESCRIBE EN EL MANUAL.'],
            'F1 digit at start' => ['9 es el número que aparece en el ejemplo del manual.'],
            'F1 decimal digit' => ['El coeficiente citado es 1.5 en el ejemplo teórico.'],
            'F1 digit with NBSP before' => ["Se cita el ejemplo\u{00A0}12 dentro del manual general."],
            'F1 digit in parentheses' => ['El manual (ver ejemplo 8) describe el concepto en abstracto.'],
            // F2 — spelled-out numbers followed within two tokens by a quantity noun.
            'F2 quince días lowercase' => ['El ejemplo menciona quince días de referencia en abstracto.'],
            'F2 QUINCE DÍAS uppercase' => ['EL EJEMPLO GENERAL MENCIONA QUINCE DÍAS POSIBLES.'],
            'F2 diez meses' => ['Se citan diez meses como referencia general sin relación con tu caso.'],
            'F2 veinte horas' => ['El manual describe veinte horas en un supuesto teórico distinto.'],
            'F2 treinta días' => ['Existen treinta días descritos de forma general en la guía.'],
            'F2 cien euros' => ['El documento resume cien euros como cifra hipotética sin más detalle.'],
            'F2 mil euros' => ['Se citan mil euros en la literatura especializada como ejemplo.'],
            'F2 una vez' => ['Se aplica una vez en el procedimiento descrito de forma general.'],
            'F2 dos largos años (one token between)' => ['El proceso dura dos largos años según el ejemplo descrito.'],
            'F2 diecisiete semanas' => ['El manual menciona diecisiete semanas como referencia abstracta.'],
            'F2 tres veces' => ['El trámite se repite tres veces en el ejemplo general descrito.'],
            'F2 cinco jornadas' => ['El concepto se ilustra con cinco jornadas en el manual general.'],
            // F3 — fractions / multiples.
            'F3 mitad' => ['El concepto se aplica sobre la mitad del periodo, en abstracto.'],
            'F3 doble' => ['En algunos casos el importe se calcula al doble, como ejemplo teórico.'],
            'F3 triple' => ['El recargo puede llegar al triple en un supuesto hipotético.'],
            'F3 trimestre' => ['El seguimiento se organiza por trimestre en términos generales.'],
            'F3 quincena' => ['El concepto de quincena se explica de forma general en el manual.'],
            'F3 bienio uppercase' => ['EL BIENIO ES UN PERIODO GENERAL DE REFERENCIA.'],
            'F3 semestre' => ['El seguimiento general se organiza por semestre, como ejemplo.'],
            'F3 tercio' => ['El cálculo teórico reduce un tercio del importe, a modo de ejemplo.'],
            // D1 — duration with an article.
            'D1 cada mes' => ['La situación se revisa cada mes según el procedimiento interno.'],
            'D1 un día' => ['El concepto se aplica un día concreto, a modo de ejemplo abstracto.'],
            'D1 por hora' => ['El cálculo teórico se describe por hora en el manual general.'],
            'D1 al año uppercase' => ['EL PROCESO SE REVISA AL AÑO, DE FORMA GENERAL.'],
            'D1 cada semana NBSP' => ["El seguimiento se organiza cada\u{00A0}semana según el manual."],
            'D1 por jornada' => ['El cálculo teórico se organiza por jornada, como ejemplo general.'],
            'D1 al mes uppercase' => ['EL SEGUIMIENTO SE ORGANIZA AL MES, DE FORMA GENERAL.'],
            // A1 — money / percentage.
            'A1 euro symbol' => ['El importe teórico se expresa en € dentro del ejemplo del manual.'],
            'A1 euros word' => ['El concepto se ilustra con una cifra en euros, a modo de ejemplo.'],
            'A1 porcentaje' => ['El manual explica el concepto de porcentaje en términos generales.'],
            'A1 percent sign' => ['El ejemplo teórico usa un 5% como referencia abstracta.'],
            'A1 smi' => ['El SMI es una cifra legal general fijada por el Estado.'],
            'A1 base reguladora' => ['La base reguladora es un concepto general del sistema de Seguridad Social.'],
            'A1 iprem' => ['El IPREM es un indicador general fijado por el Estado, como referencia.'],
            'A1 EUROS uppercase' => ['EL EJEMPLO TEÓRICO SE EXPRESA EN EUROS DE FORMA GENERAL.'],
            // E1 — second-person entitlement.
            'E1 tienes derecho' => ['Tienes derecho a disfrutar de este beneficio, según se explica.'],
            'E1 te corresponde' => ['Te corresponden estas condiciones según el marco general.'],
            'E1 puedes exigir' => ['Puedes exigir este beneficio en las condiciones descritas.'],
            'E1 te pagarán' => ['Te pagarán este concepto según lo previsto en general.'],
            'E1 cobrarás' => ['Cobrarás este concepto en los términos generales descritos.'],
            'E1 uppercase' => ['TIENES DERECHO A ESTE BENEFICIO SEGÚN EL MARCO GENERAL.'],
            'E1 te deben' => ['Te deben este concepto según lo previsto en el marco general.'],
            'E1 percibirás' => ['Percibirás este concepto en los términos generales descritos.'],
            // E2 — generic entitlement / obligation.
            'E2 derecho a' => ['Existe derecho a este beneficio dentro del marco general descrito.'],
            'E2 le corresponde' => ['A la persona trabajadora le corresponde este beneficio en general.'],
            'E2 la empresa debe' => ['La empresa debe garantizar este concepto según el marco general.'],
            'E2 es obligatorio' => ['Es obligatorio este trámite según el marco general descrito.'],
            'E2 garantiza' => ['El marco general garantiza este beneficio a la persona trabajadora.'],
            'E2 el empresario tiene que' => ['El empresario tiene que cumplir esta condición según el marco general.'],
            'E2 uppercase obligatorio' => ['ES OBLIGATORIO ESTE TRÁMITE SEGÚN EL MARCO GENERAL DESCRITO.'],
            // E3 — bounds / quantity framing.
            'E3 mínimo' => ['El periodo mínimo se establece según el marco general aplicable.'],
            'E3 máximo uppercase' => ['EL PERIODO MÁXIMO SE ESTABLECE SEGÚN EL MARCO GENERAL.'],
            'E3 al menos' => ['Debe cumplirse al menos esta condición, en términos generales.'],
            'E3 no podrá ser inferior' => ['El importe no podrá ser inferior a lo previsto en general.'],
            'E3 plazo de' => ['Existe un plazo de cumplimiento según el marco general descrito.'],
            'E3 hasta un máximo' => ['El importe se calcula hasta un máximo, según el marco general.'],
            'E3 no puede ser superior' => ['El importe no puede ser superior a lo previsto en general.'],
            // X1 — English leakage.
            'X1 entitled' => ['You are entitled to this general benefit under the framework described.'],
            'X1 you are owed' => ['In general terms, you are owed this benefit under the described framework.'],
            'X1 days' => ['The general explanation mentions several days as an abstract example.'],
            'X1 percent' => ['The general explanation mentions a percent figure as an abstract example.'],
            'X1 weeks' => ['The general explanation refers to several weeks as an abstract example.'],
            'X1 months uppercase' => ['THE GENERAL EXPLANATION REFERS TO SEVERAL MONTHS AS AN ABSTRACT EXAMPLE.'],
        ];

        return $cases;
    }

    #[DataProvider('mustBlockProvider')]
    public function test_must_block(string $text): void
    {
        $hit = GeneralLanePostCheck::scan($text);
        $this->assertNotNull($hit, "expected this text to be BLOCKED by the post-check: {$text}");
        $this->assertArrayHasKey('pattern_id', $hit);
        $this->assertArrayHasKey('matched_span', $hit);
    }

    public function test_must_block_provider_has_at_least_60_cases_with_at_least_3_per_pattern(): void
    {
        $cases = self::mustBlockProvider();
        $this->assertGreaterThanOrEqual(60, count($cases), 'expected at least 60 must-block fixtures');

        $countsByPrefix = [];
        foreach (array_keys($cases) as $label) {
            $prefix = strtok($label, ' ');
            $countsByPrefix[$prefix] = ($countsByPrefix[$prefix] ?? 0) + 1;
        }
        foreach (array_keys(GeneralLanePostCheck::PATTERNS) as $id) {
            $this->assertGreaterThanOrEqual(3, $countsByPrefix[$id] ?? 0, "expected at least 3 must-block fixtures labelled for pattern {$id}");
        }
    }

    // ---- 2. Must-pass: clean, figure-free, entitlement-free definitions --

    public static function mustPassProvider(): array
    {
        return [
            ['La excedencia es una situación en la que la persona trabajadora suspende temporalmente su actividad, sin extinguir el contrato.'],
            ['La incapacidad temporal es una situación en la que la persona trabajadora no puede trabajar por enfermedad o accidente mientras recibe asistencia sanitaria.'],
            ['El permiso retribuido es una ausencia autorizada del puesto de trabajo por un motivo concreto, reconocida por la normativa laboral.'],
            ['El convenio colectivo es el acuerdo que regula las condiciones de trabajo de un sector o empresa, negociado entre representantes.'],
            ['La reserva de puesto es la garantía de que la persona podrá reincorporarse a su puesto tras finalizar una situación determinada.'],
            ['El Estatuto de los Trabajadores es la norma que establece las condiciones laborales mínimas aplicables con carácter general.'],
            ['La conciliación laboral y familiar se refiere a las medidas que facilitan compatibilizar el trabajo con responsabilidades familiares.'],
            ['El despido es la decisión unilateral de la empresa de finalizar la relación laboral por alguna causa prevista legalmente.'],
            ['La negociación colectiva es el proceso mediante el cual se acuerdan las condiciones laborales entre empresa y representación de la plantilla.'],
            ['El período de prueba es la fase inicial del contrato en la que cualquiera de las partes puede darlo por terminado libremente.'],
            ['La subrogación empresarial ocurre cuando una empresa sucede a otra en la titularidad de un negocio, manteniendo la relación laboral.'],
            ['La movilidad geográfica se refiere al cambio de centro de trabajo que puede requerir el traslado de la persona empleada.'],
            ['El expediente de regulación temporal de empleo es un mecanismo que permite suspender o reducir la actividad laboral ante determinadas circunstancias.'],
            ['La representación legal de la plantilla se organiza mediante comités de empresa o delegados de personal, según el tamaño de la empresa.'],
            ['La formación profesional continua tiene como finalidad actualizar las competencias de la persona trabajadora a lo largo de su carrera.'],
            ['El teletrabajo es una modalidad de prestación de servicios que se realiza fuera del centro de trabajo habitual, mediante medios telemáticos.'],
        ];
    }

    #[DataProvider('mustPassProvider')]
    public function test_must_pass(string $text): void
    {
        $hit = GeneralLanePostCheck::scan($text);
        $this->assertNull($hit, 'expected this clean definition to PASS the post-check, but it was blocked by: '.json_encode($hit));
    }

    public function test_must_pass_provider_has_at_least_15_cases(): void
    {
        $this->assertGreaterThanOrEqual(15, count(self::mustPassProvider()));
    }

    // ---- 3. Coverage guard: one fixture ONLY its own pattern catches -----

    /** @return array<string,string> */
    private static function coverageOnlyFixtures(): array
    {
        return [
            'F1' => 'se registra un identificador interno 7 en el sistema',
            'F2' => 'el concepto se resume en trece dias de texto',
            'F3' => 'se aplica un ajuste doble en algunos casos excepcionales',
            'D1' => 'la situacion se revisa cada mes en la reunion de seguimiento',
            'A1' => 'el texto explica el concepto de porcentaje en terminos generales',
            'E1' => 'puedes exigir aclaraciones sobre el procedimiento interno',
            'E2' => 'existe derecho a formacion continua dentro de la empresa',
            'E3' => 'el periodo minimo se establece segun el convenio aplicable',
            'X1' => 'the explanation states you are entitled to general information only',
        ];
    }

    public function test_coverage_guard_every_pattern_has_a_fixture_only_it_catches(): void
    {
        $fixtures = self::coverageOnlyFixtures();

        foreach (array_keys(GeneralLanePostCheck::PATTERNS) as $id) {
            $this->assertArrayHasKey($id, $fixtures, "missing a coverage-only fixture for pattern {$id} — deleting it would go unnoticed");
        }

        foreach ($fixtures as $id => $text) {
            $hit = GeneralLanePostCheck::scan($text);
            $this->assertNotNull($hit, "coverage fixture for {$id} did not block at all: {$text}");
            $this->assertSame($id, $hit['pattern_id'], "coverage fixture for {$id} was caught by a DIFFERENT pattern ({$hit['pattern_id']}) — not a clean, single-pattern fixture: {$text}");

            // The load-bearing half of the guard: no OTHER pattern may also
            // match this fixture — otherwise deleting {$id} would silently
            // fall through to another pattern and this test would still
            // pass, defeating the point of a coverage guard. Fixtures are
            // authored in plain lowercase ASCII Spanish/English specifically
            // so a direct preg_match here is equivalent to what `scan()`'s
            // own normalization would produce.
            $normalized = mb_strtolower($text);
            foreach (GeneralLanePostCheck::PATTERNS as $otherId => $otherPattern) {
                if ($otherId === $id) {
                    continue;
                }
                $this->assertNotSame(
                    1,
                    preg_match($otherPattern, $normalized),
                    "coverage fixture for {$id} is ALSO caught by {$otherId} — deleting {$id} would go unnoticed: {$text}",
                );
            }
        }
    }

    // ---- F2 precision (CP-2 decision on F.8's post-check) ----------------

    public static function f2PassProvider(): array
    {
        return [
            'una situación' => ['La excedencia es una situación en la que se suspende el contrato.'],
            'una persona' => ['Se refiere a una persona trabajadora que presta servicios por cuenta ajena.'],
            'uno de los casos' => ['Es uno de los supuestos que recoge la norma en términos generales.'],
            'una modalidad' => ['El teletrabajo es una modalidad de prestación de servicios a distancia.'],
            'dos partes (non-quantity noun)' => ['El contrato se celebra entre dos partes: la empresa y la persona trabajadora.'],
            'tres supuestos' => ['La norma distingue tres supuestos de modalidad contractual.'],
            'una ausencia autorizada' => ['Es una ausencia autorizada del puesto de trabajo.'],
        ];
    }

    #[DataProvider('f2PassProvider')]
    public function test_f2_does_not_block_a_number_word_without_a_quantity_noun(string $text): void
    {
        $this->assertNull(GeneralLanePostCheck::scan($text), 'F2 must not block: '.$text);
    }

    public static function f2BlockProvider(): array
    {
        return [
            'un mes (D1)' => ['La medida se prolonga un mes en el procedimiento general.', 'D1'],
            'quince días' => ['La medida se prolonga quince días en el procedimiento general.', 'F2'],
            'dos años' => ['La medida se prolonga dos años en el procedimiento general.', 'F2'],
            'una vez' => ['Se aplica una vez terminada la fase inicial del procedimiento general.', 'F2'],
            'cien euros' => ['Se abona una cuantía de cien euros en el procedimiento general.', 'F2'],
            'accented: quince DÍAS NBSP' => ["La medida se prolonga quince\u{00A0}DÍAS en el procedimiento general.", 'F2'],
            'veinte largas semanas' => ['La medida se prolonga veinte largas semanas en el procedimiento general.', 'F2'],
        ];
    }

    #[DataProvider('f2BlockProvider')]
    public function test_f2_still_blocks_a_number_word_with_a_quantity_noun(string $text, string $expectedPattern): void
    {
        $hit = GeneralLanePostCheck::scan($text);
        $this->assertNotNull($hit, 'must block: '.$text);
        $this->assertSame($expectedPattern, $hit['pattern_id']);
    }

    // ---- Generic corresponde(n) (CP-2, forced-lane harness finding) -------

    public static function correspondeBlockProvider(): array
    {
        return [
            'derechos que corresponden a su puesto' => ['Tiene los mismos derechos y obligaciones que corresponden a su puesto en la plantilla.', 'E2'],
            'corresponde (impersonal)' => ['Corresponde a la empresa organizar el trabajo según el marco general.', 'E2'],
            'corresponderá' => ['La cuestión se resolverá y corresponderá a cada caso concreto según el marco general.', 'E2'],
            'correspondería' => ['En ese caso se correspondería con el marco general de la norma aplicable.', 'E2'],
            'UPPERCASE CORRESPONDEN' => ['LOS DERECHOS QUE CORRESPONDEN A SU PUESTO SEGÚN EL MARCO GENERAL.', 'E2'],
            'te corresponde stays E1' => ['Te corresponde este beneficio según el marco general descrito.', 'E1'],
        ];
    }

    #[DataProvider('correspondeBlockProvider')]
    public function test_generic_corresponde_is_blocked_by_e2_and_te_corresponde_by_e1(string $text, string $expectedPattern): void
    {
        $hit = GeneralLanePostCheck::scan($text);
        $this->assertNotNull($hit, 'must block: '.$text);
        $this->assertSame($expectedPattern, $hit['pattern_id']);
    }

    public function test_correspondiente_is_a_different_word_and_still_passes(): void
    {
        $this->assertNull(GeneralLanePostCheck::scan('Conviene revisar el convenio colectivo correspondiente y los documentos correspondientes al contrato.'));
    }

    // ---- The harness audit is built from F2's vocabulary (CP-2) ----------

    public function test_audit_does_not_flag_the_articles_una_and_uno(): void
    {
        foreach (self::f2PassProvider() as $label => [$text]) {
            if (preg_match('/\b(dos|tres)\b/u', mb_strtolower($text)) === 1) {
                continue; // the audit is deliberately broader: any other spelled number is flagged
            }
            $this->assertNotContains('spelled_number', GeneralLanePostCheck::audit($text), "audit false positive on '{$label}': {$text}");
        }
    }

    public function test_audit_is_never_weaker_than_f2(): void
    {
        foreach (self::f2BlockProvider() as $label => [$text, $pattern]) {
            if ($pattern !== 'F2') {
                continue;
            }
            $this->assertContains('spelled_number', GeneralLanePostCheck::audit($text), "audit missed an F2 block '{$label}': {$text}");
        }

        // Exhaustive over the vocabulary: every number word x every quantity noun that F2 blocks, the audit flags.
        $numbers = ['uno', 'una', 'dos', 'tres', 'quince', 'veinticinco', 'cien', 'ciento', 'mil', 'millones', 'doscientos'];
        $nouns = ['dias', 'dia', 'mes', 'meses', 'semana', 'semanas', 'hora', 'horas', 'ano', 'anos', 'vez', 'veces', 'euro', 'euros', 'jornada', 'jornadas', 'quincena', 'semestre', 'trimestres', 'bienio', 'trienios', 'quinquenio'];
        foreach ($numbers as $n) {
            foreach ($nouns as $q) {
                $text = "se aplica {$n} {$q} segun el marco general";
                $hit = GeneralLanePostCheck::scan($text);
                if ($hit !== null && $hit['pattern_id'] === 'F2') {
                    $this->assertContains('spelled_number', GeneralLanePostCheck::audit($text), "F2 blocks but audit misses: {$text}");
                }
            }
        }
    }

    public function test_audit_is_broader_than_f2_on_uno_una_with_a_quantity_noun_three_tokens_away(): void
    {
        $text = 'la medida se prolonga una muy larga semana en el procedimiento';
        $hit = GeneralLanePostCheck::scan($text);
        $this->assertNotSame('F2', $hit['pattern_id'] ?? null, 'F2 (two-token window) must not be what catches this');
        $this->assertContains('spelled_number', GeneralLanePostCheck::audit($text));
    }

    public function test_audit_flags_digits_and_corresponde_and_passes_the_three_forced_lane_answers_shape(): void
    {
        $this->assertContains('digit', GeneralLanePostCheck::audit('Son 12 en total.'));
        $this->assertContains('entitlement_word', GeneralLanePostCheck::audit('Los derechos que corresponden a su puesto.'));
        // The shape of the three answers the harness flagged with the stale audit: articles only, no figure.
        $clean = 'El periodo de prueba es una fase inicial del contrato, de carácter opcional, que debe pactarse por escrito. Su duración concreta depende de lo que fije el convenio colectivo aplicable.';
        $this->assertSame([], GeneralLanePostCheck::audit($clean));
        $this->assertNull(GeneralLanePostCheck::scan($clean));
    }

    // ---- Bonus: the question pre-screen (§B.6.1 condition 4) -------------

    public function test_question_prescreen_hits_on_a_figure_or_entitlement_question(): void
    {
        $this->assertTrue(GeneralLanePostCheck::questionPrescreenHit('¿Cuántos días de vacaciones tengo?'));
        $this->assertTrue(GeneralLanePostCheck::questionPrescreenHit('¿Tengo derecho a excedencia?'));
        $this->assertTrue(GeneralLanePostCheck::questionPrescreenHit('¿Cuándo me pagan la paga extra?'));
        $this->assertTrue(GeneralLanePostCheck::questionPrescreenHit('¿CUÁNTOS DÍAS ME CORRESPONDEN?'));
    }

    public function test_question_prescreen_passes_a_clean_concept_question(): void
    {
        $this->assertFalse(GeneralLanePostCheck::questionPrescreenHit('¿Qué significa estar de excedencia voluntaria?'));
        $this->assertFalse(GeneralLanePostCheck::questionPrescreenHit('¿Qué es la incapacidad temporal?'));
    }
}
