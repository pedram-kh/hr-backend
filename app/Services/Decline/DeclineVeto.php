<?php

namespace App\Services\Decline;

use App\Support\TopicLexicon;

/**
 * Slice 13e, rule D7 (plan.md §4.1) — the workplace-vocabulary veto.
 *
 * A closed, Spanish-only list of words that mean "this could be about the person's job". If any of them is in the question
 * (whole word, accent-insensitive), or any {@see TopicLexicon} anchor is, the planner's `off_domain` verdict is NOT turned
 * into a decline: the turn keeps today's escalation. A false veto costs one card HR would have got anyway; a missed veto
 * would hide a real HR question (R2). Spanish only on purpose — the English staging trigger ("what is the definition of
 * job?") must still decline. Applies to the PLANNER source only (the admin's own pattern is a human decision).
 *
 * Changing this list is a product decision (the user owns it): each word has a unit test.
 */
final class DeclineVeto
{
    /** Accent-stripped, lowercase. Multi-word entries match as phrases. */
    public const WORDS = [
        // Plan §4.1 draft
        'trabajo', 'trabajos', 'trabajar', 'trabajador', 'trabajadora', 'trabajadores',
        'empleo', 'empleado', 'empleada', 'empleados',
        'empresa', 'empresas', 'empresario', 'oficina', 'oficinas',
        'jefe', 'jefa', 'jefes', 'encargado', 'encargada', 'supervisor', 'supervisora',
        'companero', 'companera', 'companeros', 'companeras',
        'contrato', 'contratos', 'nomina', 'nominas', 'sueldo', 'sueldos', 'salario', 'salarios',
        'convenio', 'convenios', 'estatuto',
        'vacaciones', 'permiso', 'permisos', 'baja', 'bajas',
        'turno', 'turnos', 'horario', 'horarios', 'jornada', 'descanso', 'descansos',
        'despido', 'despidos', 'finiquito', 'antiguedad', 'uniforme', 'ascenso', 'ascensos',
        'plantilla', 'rrhh', 'recursos humanos', 'seguridad social', 'horas extra',
        'sindicato', 'cotizacion', 'cotizar',
        // Added at the plan review (Q7)
        'categoria', 'categorias', 'grupo', 'grupos', 'excedencia', 'excedencias',
        'formacion', 'mutua', 'paro', 'jubilacion', 'teletrabajo',
    ];

    /** The matched word / `topic:<key>`, or null when nothing in the question looks like work. */
    public static function match(string $question): ?string
    {
        $hay = TopicLexicon::stripAccents(mb_strtolower($question));

        foreach (self::WORDS as $word) {
            if (preg_match('/(?<![a-z0-9])'.preg_quote($word, '/').'(?![a-z0-9])/u', $hay) === 1) {
                return $word;
            }
        }

        $topics = array_keys(TopicLexicon::matchTopicKeys($question));

        return $topics === [] ? null : 'topic:'.$topics[0];
    }
}
