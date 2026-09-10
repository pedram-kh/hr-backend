<?php

namespace Tests\Unit;

use App\Support\EscalationExplanationGuard;
use PHPUnit\Framework\TestCase;

/**
 * Sprint 7g Item 1 (ADR-0029) — the no-new-claims check on the AI-written
 * "Resumen IA" paragraph. Passes on a faithful restatement, fails on an
 * added number, fails on an added name, fails on a dropped number, fails on
 * an empty paragraph.
 */
class EscalationExplanationGuardTest extends TestCase
{
    private function facts(): array
    {
        return [
            'asked' => 'El empleado preguntó por su periodo de prueba.',
            'found' => 'El dato de referencia dice 90 días, el convenio Hostelería de Navarra dice 60 días.',
            'stopped_reason' => 'El convenio manda siempre; un desacuerdo real escala.',
            'fix_action' => 'Revisar el dato de referencia y corregirlo si está desactualizado.',
        ];
    }

    public function test_passes_on_a_faithful_restatement_of_the_same_numbers_and_names(): void
    {
        $paragraph = 'El empleado preguntó por su periodo de prueba: el convenio Hostelería de Navarra indica 60 días, '.
            'mientras que el dato registrado dice 90 días. El convenio manda siempre, por eso este desacuerdo se escala '.
            'y se recomienda revisar y corregir el dato de referencia si está desactualizado.';

        $this->assertTrue(EscalationExplanationGuard::passes($this->facts(), $paragraph));
    }

    public function test_fails_when_the_paragraph_adds_a_new_number_not_in_the_facts(): void
    {
        $paragraph = 'El empleado preguntó por su periodo de prueba de 90 días, pero el convenio dice 60 días, '.
            'y además debería tener 15 días adicionales. Se escala para revisión.';

        $this->assertFalse(EscalationExplanationGuard::passes($this->facts(), $paragraph));
    }

    public function test_fails_when_the_paragraph_adds_a_new_proper_noun_not_in_the_facts(): void
    {
        $paragraph = 'El empleado María López preguntó por su periodo de prueba de 90 días frente a los 60 días del convenio Hostelería de Navarra. Se escala.';

        $this->assertFalse(EscalationExplanationGuard::passes($this->facts(), $paragraph));
    }

    public function test_fails_when_the_paragraph_drops_a_fact_number(): void
    {
        // Only mentions 90, silently drops the convenio's conflicting 60.
        $paragraph = 'El empleado preguntó por su periodo de prueba. El dato registrado dice 90 días. Se escala para revisión del convenio Hostelería de Navarra.';

        $this->assertFalse(EscalationExplanationGuard::passes($this->facts(), $paragraph));
    }

    public function test_fails_on_an_empty_paragraph(): void
    {
        $this->assertFalse(EscalationExplanationGuard::passes($this->facts(), ''));
        $this->assertFalse(EscalationExplanationGuard::passes($this->facts(), '   '));
    }

    public function test_a_sentence_initial_capitalized_common_word_is_not_flagged_as_a_new_name(): void
    {
        // Spanish orthography mandates a capital at the start of EVERY
        // sentence — "Se", "Por", "Como" etc. carry no "properness" signal by
        // themselves. A guard that flagged every sentence-initial word would
        // reject nearly every faithful paraphrase in practice.
        $paragraph = 'El empleado preguntó por su periodo de prueba. El convenio Hostelería de Navarra indica 60 días, '.
            'frente a los 90 días del dato registrado. Por eso este desacuerdo se escala, ya que el convenio manda '.
            'siempre. Conviene revisar y corregir el dato de referencia si está desactualizado.';

        $this->assertTrue(EscalationExplanationGuard::passes($this->facts(), $paragraph));
    }

    public function test_a_real_multi_word_name_at_sentence_start_is_still_flagged(): void
    {
        // The exemption above is single-word-only — a genuine two-word name
        // (first + last) is still caught even at sentence-start, since a real
        // name there is rare enough that exempting it would be unsafe.
        $paragraph = 'María López preguntó por su periodo de prueba de 90 días frente a los 60 días del convenio Hostelería de Navarra. Se escala.';

        $this->assertFalse(EscalationExplanationGuard::passes($this->facts(), $paragraph));
    }

    public function test_allows_common_domain_capitalized_tokens_that_are_not_really_new_names(): void
    {
        $facts = [
            'asked' => 'El empleado preguntó por su salario.',
            'found' => 'No existe tabla salarial cargada para este convenio.',
            'stopped_reason' => 'Sin fila estructurada, el sistema nunca adivina una cifra.',
            'fix_action' => 'Cargar la tabla salarial de este convenio.',
        ];
        // Uses "El", "La", "Recursos Humanos", "Tabla" — all in ALWAYS_ALLOWED —
        // plus nothing else new; must still pass.
        $paragraph = 'El empleado preguntó por su salario. La Tabla salarial de este convenio no existe todavía. '.
            'Recursos Humanos debe cargarla, ya que el sistema nunca adivina una cifra sin fila estructurada.';

        $this->assertTrue(EscalationExplanationGuard::passes($facts, $paragraph));
    }
}
