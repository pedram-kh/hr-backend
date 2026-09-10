<?php

namespace App\Support;

use App\Models\ReferenceFact;

/**
 * EscalationExplainer — Sprint 7g Item 1 (ADR-0029).
 *
 * A pure function: (reason, trace) -> structured facts for HR. No provider
 * call, no write. The two informational trace fields it leans on for the
 * `reference_fact_coverage_gap` split (`employee_group_state`,
 * `coverage_gap_detail`) are computed upstream, in `ReferenceFactAnswerService`,
 * at answer time — additive, read-only enrichments that change no decision.
 *
 * Every entry states, in order: WHAT WAS ASKED (topically, not the raw
 * question — the card already shows that separately), WHAT WAS FOUND (named,
 * not just "nothing"), WHY IT STOPPED (the specific gate/rule), and THE FIX
 * (action + which admin surface + a deep link straight to it). `employee_told`
 * is always the ONE fixed neutral message, mirrored here so a caller never has
 * to import `ChatService` just to show what the employee saw.
 *
 * COVERAGE: `MATRIX` lists every (reason.sub_outcome) pair the live pipeline
 * can produce for a chat escalation card, PLUS the publish-time 409 reasons
 * (which do not create a card today — `EscalationController::resolve()`
 * returns them directly — but get an entry here too, per the sprint spec, so
 * the same explainer can describe them if a future surface needs to).
 * `EscalationExplainerGuardTest` walks `MATRIX` and fails the build if
 * `registry()` is missing any of them — the "no enum value left unexplained"
 * guarantee.
 */
final class EscalationExplainer
{
    /** Every (reason.sub_outcome) key this explainer must cover. Guard-tested. */
    public const MATRIX = [
        // --- pre-retrieval guardrail baseline (never reaches the router) -----
        'sensitive_topic.pattern_baseline',
        'sensitive_topic.admin_blocked_topic',
        'off_domain.legal_medical',
        'off_domain.other_employee_data',
        'off_domain.router_off_domain',
        'off_domain.admin_off_domain',
        // --- reserved, not currently emitted, still explainable ---------------
        'explicit_request.explicit_request',
        // --- the answer-or-escalate floor (prose path) -------------------------
        'low_confidence.no_retrieval',
        'low_confidence.weak_retrieval',
        'low_confidence.citations_failed',
        'low_confidence.figure_not_grounded',
        'low_confidence.entailment_failed',
        'low_confidence.grounding_truncated',
        'low_confidence.aggregation',
        'low_confidence.cross_path',
        'low_confidence.answer_model_not_configured',
        'low_confidence.provider_error',
        'low_confidence.unspecified',
        // --- Sprint 7c Phase 2 composition ------------------------------------
        'conflict.fact_vs_convenio',
        // --- salary SQL path (ADR-0006/0014) -----------------------------------
        'salary_coverage_gap.no_convenio',
        'salary_coverage_gap.no_table',
        'salary_coverage_gap.future_only',
        'salary_coverage_gap.category_unresolved',
        'salary_coverage_gap.no_row_for_category',
        // --- reference-fact path (Sprint 7c Phase 1, 7f group scoping) --------
        'reference_fact_coverage_gap.no_convenio',
        'reference_fact_coverage_gap.no_reference_data',
        'reference_fact_coverage_gap.only_needs_review',
        'reference_fact_coverage_gap.out_of_validity',
        'reference_fact_coverage_gap.employee_group_unknown',
        'reference_fact_coverage_gap.group_structure_not_approved',
        'reference_fact_coverage_gap.subarea_not_recorded',
        'reference_fact_coverage_gap.group_split_since_fact_bound',
        'reference_fact_coverage_gap.same_validity_conflict',
        // --- publish-time 409s (escalation_events / resolve() — no card today) -
        'publish.topic_scope_conflict',
        'publish.semantic_overlap',
        'publish.semantic_near_overlap',
        'publish.semantic_compare_unavailable',
        'publish.semantic_no_text_to_compare',
        'publish.convert_blocked',
    ];

    /**
     * @param  array<string,mixed>  $trace
     * @return array{reason:string, sub_outcome:string, asked:string, found:string, stopped_reason:string, fix_action:string, fix_surface:string, fix_link:?string, employee_told:string}
     */
    public static function explain(string $reason, array $trace): array
    {
        $subOutcome = self::detectSubOutcome($reason, $trace);
        $builder = self::registry()[$reason][$subOutcome] ?? self::registry()['_fallback'][$reason] ?? null;
        $facts = $builder !== null ? $builder($trace) : self::genericFallback($reason);

        return [
            'reason' => $reason,
            'sub_outcome' => $subOutcome,
            'asked' => $facts['asked'],
            'found' => $facts['found'],
            'stopped_reason' => $facts['stopped_reason'],
            'fix_action' => $facts['fix_action'],
            'fix_surface' => $facts['fix_surface'],
            'fix_link' => $facts['fix_link'],
            'employee_told' => \App\Services\ChatService::EMPLOYEE_ESCALATION_MESSAGE,
        ];
    }

    /**
     * Render the structured facts as plain sentences — the fallback shown on
     * the card header when the AI paragraph is null (provider failure, or the
     * no-new-claims check rejected it). Deterministic; states nothing the facts
     * themselves don't already say.
     */
    public static function factsToSentences(array $facts): string
    {
        return trim(sprintf(
            "%s %s %s Acción sugerida: %s.",
            $facts['asked'],
            $facts['found'],
            $facts['stopped_reason'],
            $facts['fix_action']
        ));
    }

    /**
     * Introspection for the guard test — true iff `registry()` has a builder
     * for `$matrixKey` (a "reason.sub_outcome" string from MATRIX), WITHOUT
     * falling through to any fallback (a fallback masks a real gap for a live
     * caller, but must not mask one for the guard test).
     */
    public static function registryHasEntry(string $matrixKey): bool
    {
        [$reason, $subOutcome] = array_pad(explode('.', $matrixKey, 2), 2, null);

        return isset(self::registry()[$reason][$subOutcome]);
    }

    // -------------------------------------------------------------------------
    // Sub-outcome detection (reason + trace -> the specific rule that fired)
    // -------------------------------------------------------------------------

    private static function detectSubOutcome(string $reason, array $trace): string
    {
        return match ($reason) {
            'sensitive_topic' => (($trace['guardrail_check']['layer'] ?? null) === 'admin') ? 'admin_blocked_topic' : 'pattern_baseline',
            'off_domain' => self::offDomainSubOutcome($trace),
            'explicit_request' => 'explicit_request',
            'low_confidence' => self::lowConfidenceSubOutcome($trace),
            'conflict' => 'fact_vs_convenio',
            'salary_coverage_gap' => self::salarySubOutcome($trace),
            'reference_fact_coverage_gap' => self::referenceFactSubOutcome($trace),
            default => 'unspecified',
        };
    }

    private static function offDomainSubOutcome(array $trace): string
    {
        $rule = $trace['guardrail_check']['rule'] ?? null;
        if ($rule === 'legal_medical') {
            return 'legal_medical';
        }
        if ($rule === 'other_employee_data') {
            return 'other_employee_data';
        }
        if (($trace['guardrail_check']['layer'] ?? null) === 'admin') {
            return 'admin_off_domain';
        }

        return 'router_off_domain';
    }

    private static function lowConfidenceSubOutcome(array $trace): string
    {
        $fd = $trace['floor_decision'] ?? [];
        $note = (string) ($fd['note'] ?? '');

        if (($trace['aggregation_guard']['fired'] ?? false) === true) {
            return 'aggregation';
        }
        if (($fd['path'] ?? null) === 'salary_prose_crosspath' || ($trace['router_decision']['cross_path'] ?? false)) {
            return 'cross_path';
        }
        if (str_contains($note, 'answer model not configured')) {
            return 'answer_model_not_configured';
        }
        if (str_contains($note, 'provider error')) {
            return 'provider_error';
        }
        if (($fd['check_a_retrieval'] ?? true) === false) {
            return str_contains($note, 'no eligible chunks') ? 'no_retrieval' : 'weak_retrieval';
        }
        if (($fd['check_b_citations'] ?? true) === false) {
            return 'citations_failed';
        }
        $fig = $fd['figure_grounding'] ?? [];
        if (($fig['grounded'] ?? true) === false) {
            return 'figure_not_grounded';
        }
        $gr = $fd['grounding'] ?? [];
        if (($gr['trace_fragment']['grounding_truncated'] ?? false) === true || str_contains($note, 'truncated')) {
            return 'grounding_truncated';
        }
        if (($gr['grounded'] ?? true) === false) {
            return 'entailment_failed';
        }

        return 'unspecified';
    }

    private static function salarySubOutcome(array $trace): string
    {
        $note = (string) ($trace['salary']['note'] ?? '');

        return match (true) {
            str_contains($note, 'no convenio on profile') => 'no_convenio',
            str_contains($note, 'not-yet-effective') => 'future_only',
            str_contains($note, 'selected category not valid') => 'category_unresolved',
            str_contains($note, 'no salary row for this category') => 'no_row_for_category',
            default => 'no_table',
        };
    }

    private static function referenceFactSubOutcome(array $trace): string
    {
        $rf = $trace['reference_fact'] ?? [];
        $note = (string) ($rf['note'] ?? '');

        if (str_contains($note, 'no convenio on profile')) {
            return 'no_convenio';
        }
        if (str_contains($note, 'same-validity') || ($rf['validity_selection'] ?? null) === 'ambiguous_conflict') {
            return 'same_validity_conflict';
        }
        if (str_contains($note, 'sub-area is unknown')) {
            return 'subarea_not_recorded';
        }
        if (str_contains($note, 'splits into sub-areas')) {
            return 'group_split_since_fact_bound';
        }
        if (str_contains($note, 'no verified in-scope in-validity fact')) {
            return match ($rf['coverage_gap_detail']['case'] ?? null) {
                'only_needs_review' => 'only_needs_review',
                'out_of_validity' => 'out_of_validity',
                default => 'no_reference_data',
            };
        }
        if (str_contains($note, 'only per-group/per-category facts exist')) {
            return match ($rf['employee_group_state'] ?? null) {
                'unresolved' => 'employee_group_unknown',
                'unapproved_or_missing' => 'group_structure_not_approved',
                default => 'subarea_not_recorded',
            };
        }

        return 'no_reference_data';
    }

    // -------------------------------------------------------------------------
    // The registry: reason -> sub_outcome -> callable(trace): facts
    // -------------------------------------------------------------------------

    /**
     * @return array<string, array<string, callable(array<string,mixed>):array{asked:string,found:string,stopped_reason:string,fix_action:string,fix_surface:string,fix_link:?string}>>
     */
    private static function registry(): array
    {
        // Sprint 7g Item 2 deep-link scheme (AdminShell): `#view=<view>` selects
        // the tab; `#doc=<uuid>` stays the Sprint 7e compat form (bare, implies
        // view=documents); `tab=`/`fact=`/`emp=`/`convenio=` are additive keys
        // on the SAME hash the Review/Directory/Groups surfaces read on mount.
        $groupsLink = fn (?int $convenioId) => $convenioId !== null
            ? "#view=review&tab=groups&convenio={$convenioId}"
            : '#view=review&tab=groups';
        $factLink = fn (?string $uuid) => $uuid !== null
            ? "#view=review&tab=reference-facts&fact={$uuid}"
            : '#view=review&tab=reference-facts';
        $empLink = fn (?string $uuid) => $uuid !== null
            ? "#view=directory&emp={$uuid}"
            : '#view=directory';
        $vocabLink = fn () => '#view=review&tab=vocabulary';
        $taggingLink = fn () => '#view=review&tab=tagging';
        $documentsLink = fn () => '#view=documents';
        $guardrailsLink = fn () => '#view=guardrails';
        $settingsLink = fn () => '#view=settings';

        return [
            'sensitive_topic' => [
                'pattern_baseline' => fn (array $t) => [
                    'asked' => 'El empleado hizo una pregunta que coincide con un patrón de tema sensible (acoso, salud mental, o un procedimiento disciplinario/despido).',
                    'found' => 'Ningún dato — el filtro de seguridad hardcodeado detuvo la pregunta ANTES de llamar al modelo o buscar en el convenio (regla: '.($t['guardrail_check']['rule'] ?? 'sensitive_topic').').',
                    'stopped_reason' => 'Estos temas escalan siempre, sin excepción, y nunca llegan al proveedor externo (garantía de privacidad, ADR-0015).',
                    'fix_action' => 'Responder directamente a la persona empleada por otro canal; no hay nada que corregir en el sistema.',
                    'fix_surface' => 'ninguna — atención directa',
                    'fix_link' => null,
                ],
                'admin_blocked_topic' => fn (array $t) => [
                    'asked' => 'El empleado hizo una pregunta que coincide con un tema bloqueado configurado por un administrador (capa adicional sobre el filtro base).',
                    'found' => 'Ningún dato — coincide con el patrón: '.($t['guardrail_check']['matched_pattern'] ?? '(patrón admin)').'.',
                    'stopped_reason' => 'La capa de guardarraíles de administrador (Sprint 6, ADR-0019) añade bloqueos; nunca puede debilitar el filtro base.',
                    'fix_action' => 'Revisar si el patrón bloqueado sigue siendo correcto en Guardarraíles; ajustarlo si está capturando preguntas legítimas.',
                    'fix_surface' => 'Guardarraíles (admin)',
                    'fix_link' => $guardrailsLink(),
                ],
            ],
            'off_domain' => [
                'legal_medical' => fn (array $t) => [
                    'asked' => 'El empleado pidió consejo legal o médico (asesoramiento jurídico, diagnóstico, tratamiento).',
                    'found' => 'Ningún dato — el sistema no da asesoramiento legal ni médico, tenga o no texto relevante en el convenio.',
                    'stopped_reason' => 'Filtro base, regla legal_medical: fuera de ámbito por diseño, siempre.',
                    'fix_action' => 'Responder directamente o derivar a asesoría legal/médica externa; no hay nada que corregir en el sistema.',
                    'fix_surface' => 'ninguna — atención directa',
                    'fix_link' => null,
                ],
                'other_employee_data' => fn (array $t) => [
                    'asked' => 'El empleado preguntó por datos de OTRA persona (p. ej. cuánto gana/cobra alguien nombrado).',
                    'found' => 'Ningún dato — la pregunta nombra a un tercero; el sistema nunca responde sobre los datos de otra persona.',
                    'stopped_reason' => 'Filtro base, regla other_employee_data: protección de privacidad, siempre activa.',
                    'fix_action' => 'Nada que corregir; si la pregunta era legítima sobre el propio empleado, pedirle que la reformule sin nombrar a otra persona.',
                    'fix_surface' => 'ninguna — atención directa',
                    'fix_link' => null,
                ],
                'router_off_domain' => fn (array $t) => [
                    'asked' => 'El router (modelo barato) clasificó la pregunta como fuera del ámbito de RR.HH./convenio.',
                    'found' => 'Ningún dato — no se buscó en el convenio porque el router decidió que la pregunta no es sobre convenio/salario/RR.HH.',
                    'stopped_reason' => 'Clasificación off_domain del router (ADR-0016); nota: "'.($t['router_decision']['note'] ?? '—').'".',
                    'fix_action' => 'Si la pregunta SÍ era sobre convenio/RR.HH., revisar la clasificación del router y considerar añadir un caso de prueba.',
                    'fix_surface' => 'Revisión · Vocabulario/AI tagging',
                    'fix_link' => $vocabLink(),
                ],
                'admin_off_domain' => fn (array $t) => [
                    'asked' => 'El empleado hizo una pregunta que coincide con un tema bloqueado como off_domain por un administrador.',
                    'found' => 'Ningún dato — coincide con el patrón: '.($t['guardrail_check']['matched_pattern'] ?? '(patrón admin)').'.',
                    'stopped_reason' => 'Capa de guardarraíles de administrador (Sprint 6, ADR-0019), regla off_domain.',
                    'fix_action' => 'Revisar el patrón bloqueado en Guardarraíles; ajustarlo si está capturando preguntas legítimas.',
                    'fix_surface' => 'Guardarraíles (admin)',
                    'fix_link' => $guardrailsLink(),
                ],
            ],
            'explicit_request' => [
                'explicit_request' => fn (array $t) => [
                    'asked' => 'El empleado pidió explícitamente hablar con una persona de Recursos Humanos.',
                    'found' => 'No aplica — es una petición directa, no una búsqueda fallida.',
                    'stopped_reason' => 'Petición explícita del empleado.',
                    'fix_action' => 'Atender la conversación normalmente; no hay nada que corregir en el sistema.',
                    'fix_surface' => 'ninguna — atención directa',
                    'fix_link' => null,
                ],
            ],
            'low_confidence' => [
                'no_retrieval' => fn (array $t) => [
                    'asked' => 'Una pregunta de convenio/RR.HH. sin coincidencia en el corpus indexado.',
                    'found' => 'Cero fragmentos elegibles en la búsqueda ('.($t['retrieval']['eligible_total'] ?? 0).' elegibles totales).',
                    'stopped_reason' => 'Suelo de recuperación (Check A): sin fragmentos elegibles, nunca se sintetiza una respuesta.',
                    'fix_action' => 'Comprobar si el documento que cubre este tema existe y está etiquetado/embebido correctamente; si no existe, considerar añadirlo.',
                    'fix_surface' => 'Documentos',
                    'fix_link' => $documentsLink(),
                ],
                'weak_retrieval' => fn (array $t) => [
                    'asked' => 'Una pregunta de convenio/RR.HH. con fragmentos candidatos, pero todos por debajo del umbral de confianza.',
                    'found' => sprintf('Fragmentos elegibles (top score %.4f), pero por debajo del suelo de recuperación.', (float) ($t['retrieval']['top_score'] ?? 0.0)),
                    'stopped_reason' => 'Suelo de recuperación (Check A): la mejor coincidencia no alcanza el umbral mínimo de similitud.',
                    'fix_action' => 'Revisar si el convenio correcto está indexado y etiquetado con el tema; el fragmento relevante puede estar fragmentado o mal etiquetado.',
                    'fix_surface' => 'Documentos',
                    'fix_link' => $documentsLink(),
                ],
                'citations_failed' => fn (array $t) => [
                    'asked' => 'Una pregunta de convenio/RR.HH. con fragmentos elegibles.',
                    'found' => 'El modelo no citó ningún fragmento proporcionado (o no generó respuesta) — Check B fallido.',
                    'stopped_reason' => 'Verificación de citas (Check B): una respuesta sin cita válida nunca se muestra.',
                    'fix_action' => 'Revisar la llamada de síntesis para esta pregunta en el panel de trazas; puede ser un problema puntual del proveedor.',
                    'fix_surface' => 'AI tagging (revisión)',
                    'fix_link' => $taggingLink(),
                ],
                'figure_not_grounded' => fn (array $t) => [
                    'asked' => 'Una pregunta de convenio/RR.HH. con una respuesta generada que incluye una cifra.',
                    'found' => 'La respuesta contiene una cifra que no aparece en ningún fragmento citado: '.implode(', ', $t['floor_decision']['figure_grounding']['ungrounded'] ?? ['(no identificada)']).'.',
                    'stopped_reason' => 'Comprobación de cifras (pre-check determinista antes de /ground): una cifra no respaldada nunca se muestra.',
                    'fix_action' => 'Revisar el fragmento citado; si el dato correcto existe en el convenio pero en otro fragmento, puede ser un problema de fragmentación.',
                    'fix_surface' => 'Documentos',
                    'fix_link' => $documentsLink(),
                ],
                'entailment_failed' => fn (array $t) => [
                    'asked' => 'Una pregunta de convenio/RR.HH. con una respuesta generada y citas válidas.',
                    'found' => 'Al menos una afirmación de la respuesta no está respaldada literalmente por su fragmento citado (verificado por el modelo de razonamiento): '.implode('; ', $t['floor_decision']['grounding']['ungrounded'] ?? ['(no especificado)']).'.',
                    'stopped_reason' => 'Verificación de fundamentación por afirmación (§5, la puerta real): cualquier afirmación no fundamentada escala.',
                    'fix_action' => 'Leer la afirmación y el fragmento citado en el panel de trazas; si el convenio SÍ lo dice, puede ser una redacción ambigua que confunde al verificador.',
                    'fix_surface' => 'AI tagging (revisión)',
                    'fix_link' => $taggingLink(),
                ],
                'grounding_truncated' => fn (array $t) => [
                    'asked' => 'Una pregunta de convenio/RR.HH. con una respuesta generada y citas válidas.',
                    'found' => 'La verificación de fundamentación se truncó tras un reintento — resultado indeterminado, no una afirmación confirmada como falsa.',
                    'stopped_reason' => 'Un fallo de fundamentación truncado escala igual que uno negativo (suelo conservador), pero es un fallo técnico, no un dato incorrecto.',
                    'fix_action' => 'Reintentar la pregunta; si persiste, puede ser un problema de latencia/tamaño del proveedor externo.',
                    'fix_surface' => 'ninguna — probable fallo transitorio',
                    'fix_link' => null,
                ],
                'aggregation' => fn (array $t) => [
                    'asked' => 'El empleado preguntó por un TOTAL agregado de varios tipos de días libres (p. ej. "días libres en total").',
                    'found' => 'No se buscó — sumar tipos de permiso distintos no es un dato único que se pueda fundamentar con exactitud.',
                    'stopped_reason' => 'Guarda de agregación (Corrección-03, Fix 2): una pregunta de "total" agregado escala antes de la recuperación.',
                    'fix_action' => 'Responder desglosando cada tipo de día libre por separado (vacaciones, asuntos propios, permisos específicos).',
                    'fix_surface' => 'ninguna — respuesta manual desglosada',
                    'fix_link' => null,
                ],
                'cross_path' => fn (array $t) => [
                    'asked' => 'Una pregunta compuesta que combina una parte salarial (SQL) con otra parte no salarial (prosa).',
                    'found' => 'La parte salarial se detectó, pero la parte de prosa no se respondió — para no descartarla en silencio, ambas se derivan.',
                    'stopped_reason' => 'Guarda de cruce salario+prosa (Corrección-03, Fix 3): un salto directo a SQL habría descartado silenciosamente la mitad de la pregunta.',
                    'fix_action' => 'Responder ambas partes por separado: la cifra salarial (consultable en el chat) y el tema no salarial.',
                    'fix_surface' => 'ninguna — respuesta manual en dos partes',
                    'fix_link' => null,
                ],
                'answer_model_not_configured' => fn (array $t) => [
                    'asked' => 'Una pregunta de convenio/RR.HH. con fragmentos elegibles por encima del suelo de recuperación.',
                    'found' => 'Nada se sintetizó — no hay una clave de modelo de respuesta configurada.',
                    'stopped_reason' => 'Sin modelo de respuesta configurado, el sistema nunca inventa una respuesta; escala.',
                    'fix_action' => 'Configurar la clave del modelo de respuesta en Ajustes · Modelo de respuesta.',
                    'fix_surface' => 'Ajustes (admin)',
                    'fix_link' => $settingsLink(),
                ],
                'provider_error' => fn (array $t) => [
                    'asked' => 'Una pregunta de convenio/RR.HH. con fragmentos elegibles por encima del suelo de recuperación.',
                    'found' => 'La llamada al proveedor externo falló ('.($t['synthesis']['error'] ?? $t['composition']['synthesis_error'] ?? 'error de proveedor').').',
                    'stopped_reason' => 'Un fallo del proveedor siempre escala (nunca se muestra una respuesta sin verificar).',
                    'fix_action' => 'Comprobar el estado del proveedor externo y la validez de la clave configurada; probable fallo transitorio.',
                    'fix_surface' => 'Ajustes (admin)',
                    'fix_link' => $settingsLink(),
                ],
                'unspecified' => fn (array $t) => [
                    'asked' => 'Una pregunta de convenio/RR.HH.',
                    'found' => 'El motivo exacto no coincide con ningún patrón conocido del suelo de respuesta-o-escalada.',
                    'stopped_reason' => 'Escalada por baja confianza — revisar el panel de trazas completo para el detalle exacto.',
                    'fix_action' => 'Revisar la traza completa de este turno en el panel de trazas.',
                    'fix_surface' => 'AI tagging (revisión)',
                    'fix_link' => $taggingLink(),
                ],
            ],
            'conflict' => [
                'fact_vs_convenio' => fn (array $t) => [
                    'asked' => 'Una pregunta cuyo tema tiene tanto un dato de referencia verificado como texto de convenio vigente.',
                    'found' => sprintf(
                        'Desacuerdo en la misma unidad (%s): el dato de referencia dice %s, el convenio dice %s.',
                        $t['composition']['conflict']['unit'] ?? '?',
                        implode('/', $t['composition']['conflict']['fact_values'] ?? []),
                        implode('/', $t['composition']['conflict']['prose_values'] ?? [])
                    ),
                    'stopped_reason' => 'El convenio manda siempre; un desacuerdo real nunca se mezcla ni se resuelve a favor del dato — escala (ADR-0023).',
                    'fix_action' => 'Revisar el dato de referencia: si está desactualizado o mal capturado, corregirlo o marcarlo needs_review.',
                    'fix_surface' => 'Reference facts (revisión)',
                    'fix_link' => $factLink($t['reference_fact']['fact_uuid'] ?? null),
                ],
            ],
            'salary_coverage_gap' => [
                'no_convenio' => fn (array $t) => [
                    'asked' => 'El empleado preguntó por su salario.',
                    'found' => 'El perfil del empleado no tiene convenio asignado.',
                    'stopped_reason' => 'Sin convenio no hay ámbito estructurado de tabla salarial.',
                    'fix_action' => 'Asignar el convenio correcto al empleado en el Directorio.',
                    'fix_surface' => 'Directorio',
                    'fix_link' => $empLink($t['profile']['employee_uuid'] ?? null),
                ],
                'no_table' => fn (array $t) => [
                    'asked' => 'El empleado preguntó por su salario.',
                    'found' => 'No existe ninguna tabla salarial cargada para este convenio (posiblemente solo en PDF, sin convertir).',
                    'stopped_reason' => 'Hueco de cobertura: sin fila estructurada, el sistema nunca adivina una cifra (ADR-0014).',
                    'fix_action' => 'Cargar/convertir la tabla salarial de este convenio (ver el runbook salary:pdf-to-xlsx si el origen es PDF).',
                    'fix_surface' => 'Documentos',
                    'fix_link' => $documentsLink(),
                ],
                'future_only' => fn (array $t) => [
                    'asked' => 'El empleado preguntó por su salario.',
                    'found' => 'Solo existe una tabla salarial futura (aún no vigente) para este convenio.',
                    'stopped_reason' => 'Nunca se cita una cifra que todavía no está en vigor.',
                    'fix_action' => 'Nada que corregir — la tabla se activará automáticamente en su fecha de vigencia.',
                    'fix_surface' => 'ninguna — pendiente de fecha',
                    'fix_link' => null,
                ],
                'category_unresolved' => fn (array $t) => [
                    'asked' => 'El empleado preguntó por su salario y eligió/tiene una categoría profesional.',
                    'found' => 'La categoría indicada no es válida para el convenio del empleado.',
                    'stopped_reason' => 'Una categoría fuera del convenio nunca se acepta (protección de integridad referencial).',
                    'fix_action' => 'Confirmar/corregir la categoría profesional del empleado en el Directorio.',
                    'fix_surface' => 'Directorio',
                    'fix_link' => $empLink($t['profile']['employee_uuid'] ?? null),
                ],
                'no_row_for_category' => fn (array $t) => [
                    'asked' => 'El empleado preguntó por su salario; existe una tabla para su convenio/año.',
                    'found' => 'La tabla no tiene fila para la categoría profesional del empleado.',
                    'stopped_reason' => 'Hueco de cobertura dentro de una tabla existente: sin fila, no hay cifra que citar.',
                    'fix_action' => 'Revisar la tabla salarial de origen y añadir la fila que falta para esta categoría.',
                    'fix_surface' => 'Documentos',
                    'fix_link' => $documentsLink(),
                ],
            ],
            'reference_fact_coverage_gap' => [
                'no_convenio' => fn (array $t) => [
                    'asked' => 'El empleado preguntó por un dato de referencia estructurado.',
                    'found' => 'El perfil del empleado no tiene convenio asignado.',
                    'stopped_reason' => 'Sin convenio no hay ámbito para buscar el dato.',
                    'fix_action' => 'Asignar el convenio correcto al empleado en el Directorio.',
                    'fix_surface' => 'Directorio',
                    'fix_link' => $empLink($t['profile']['employee_uuid'] ?? null),
                ],
                'no_reference_data' => fn (array $t) => [
                    'asked' => 'El empleado preguntó por un tema que tiene un dato de referencia esperado para su convenio.',
                    'found' => 'No existe ningún dato de referencia (verificado o no) para este convenio y tema.',
                    'stopped_reason' => 'Hueco de cobertura: nada capturado todavía para esta combinación convenio+tema.',
                    'fix_action' => 'Capturar el dato de referencia (manual o vía segmentación de un documento fuente) para este convenio y tema.',
                    'fix_surface' => 'Reference facts (revisión)',
                    'fix_link' => $factLink(null),
                ],
                'only_needs_review' => fn (array $t) => [
                    'asked' => 'El empleado preguntó por un tema con un dato de referencia propuesto por IA.',
                    'found' => 'Existe un dato propuesto (needs_review), pero ningún humano lo ha verificado todavía.',
                    'stopped_reason' => 'Un dato no verificado nunca se cita al empleado (inerte-hasta-verificado, ADR-0020/0021).',
                    'fix_action' => 'Verificar (o rechazar) el dato propuesto en la cola de revisión.',
                    'fix_surface' => 'Reference facts (revisión)',
                    'fix_link' => $factLink(null),
                ],
                'out_of_validity' => fn (array $t) => [
                    'asked' => 'El empleado preguntó por un tema con un dato de referencia verificado, pero fuera de la fecha actual.',
                    'found' => 'El dato verificado existe pero su ventana de vigencia no cubre la fecha de hoy (caducado o todavía no vigente).',
                    'stopped_reason' => 'Nunca se cita un dato fuera de su ventana de vigencia.',
                    'fix_action' => 'Cargar/verificar la versión vigente del dato para la fecha actual.',
                    'fix_surface' => 'Reference facts (revisión)',
                    'fix_link' => $factLink(null),
                ],
                'employee_group_unknown' => fn (array $t) => [
                    'asked' => 'El empleado preguntó por un tema cuyo dato de referencia está segmentado por grupo/categoría en este convenio.',
                    'found' => 'El empleado no tiene un grupo/sub-área asignado en su perfil.',
                    'stopped_reason' => 'Sin grupo asignado, el sistema nunca adivina cuál de los datos por grupo le corresponde (ADR-0028).',
                    'fix_action' => 'Asignar el grupo/sub-área del empleado en el Directorio.',
                    'fix_surface' => 'Directorio',
                    'fix_link' => $empLink($t['profile']['employee_uuid'] ?? null),
                ],
                'group_structure_not_approved' => fn (array $t) => [
                    'asked' => 'El empleado preguntó por un tema cuyo dato de referencia está segmentado por grupo/categoría en este convenio.',
                    'found' => 'El nodo de grupo asignado al empleado no existe o no está aprobado todavía (sigue needs_review).',
                    'stopped_reason' => 'Un nodo no aprobado es invisible para el emparejador — igual que si no existiera (ADR-0028).',
                    'fix_action' => 'Aprobar la estructura de grupos de este convenio en la pestaña Groups.',
                    'fix_surface' => 'Groups (revisión)',
                    'fix_link' => $groupsLink($t['profile']['convenio_id'] ?? null),
                ],
                'subarea_not_recorded' => fn (array $t) => [
                    'asked' => 'El empleado preguntó por un tema cuyo dato de referencia está segmentado por sub-área dentro de su grupo.',
                    'found' => 'El dato está vinculado a una sub-área específica del grupo del empleado, pero no sabemos en qué sub-área está el empleado.',
                    'stopped_reason' => 'Responder al nivel de grupo sería menos específico que la evidencia disponible — se escala en vez de adivinar (ADR-0028).',
                    'fix_action' => 'Asignar la sub-área concreta del empleado en el Directorio.',
                    'fix_surface' => 'Directorio',
                    'fix_link' => $empLink($t['profile']['employee_uuid'] ?? null),
                ],
                'group_split_since_fact_bound' => fn (array $t) => [
                    'asked' => 'El empleado preguntó por un tema cuyo dato de referencia está vinculado a un grupo que el convenio ha dividido después en sub-áreas.',
                    'found' => 'El dato sigue vinculado al grupo entero, pero el convenio ahora tiene sub-áreas con valores distintos — el ámbito del propio dato es ambiguo.',
                    'stopped_reason' => 'El ámbito ambiguo es el del DATO, no el del empleado — se escala hasta que el dato se re-vincule a la sub-área correcta.',
                    'fix_action' => 'Re-vincular el dato de referencia a la(s) sub-área(s) correctas en la pestaña Reference facts / Groups.',
                    'fix_surface' => 'Reference facts (revisión)',
                    'fix_link' => $factLink($t['reference_fact']['fact_uuid'] ?? null),
                ],
                'same_validity_conflict' => fn (array $t) => [
                    'asked' => 'El empleado preguntó por un tema con más de un dato de referencia verificado en el mismo nivel de ámbito.',
                    'found' => 'Dos datos verificados comparten la validez más reciente pero tienen valores distintos.',
                    'stopped_reason' => 'Un conflicto genuino nunca se mezcla ni se elige al azar — se escala (la resolución de versiones es Sprint 7d).',
                    'fix_action' => 'Revisar ambos datos verificados y corregir/retirar el que esté desactualizado o mal capturado.',
                    'fix_surface' => 'Reference facts (revisión)',
                    'fix_link' => $factLink(null),
                ],
            ],
            'publish' => [
                'topic_scope_conflict' => fn (array $t) => [
                    'asked' => 'Un/a agente de RR.HH. intentó publicar una resolución como conocimiento (internal_hr_ruling).',
                    'found' => 'El ámbito ya tiene un convenio vigente sin el tema etiquetado (fence estructural, fail-closed).',
                    'stopped_reason' => 'Sin etiqueta de tema, el fence estructural bloquea SIEMPRE, antes de la comparación semántica.',
                    'fix_action' => 'Etiquetar el tema del convenio activo de este ámbito.',
                    'fix_surface' => 'AI tagging (revisión)',
                    'fix_link' => $taggingLink(),
                ],
                'semantic_overlap' => fn (array $t) => [
                    'asked' => 'Un/a agente de RR.HH. intentó publicar una resolución como conocimiento.',
                    'found' => 'El texto propuesto solapa casi verbatim con el convenio oficial vigente en el mismo punto.',
                    'stopped_reason' => 'El convenio manda siempre; una resolución interna nunca puede prevalecer sobre él (ADR-0024, banda 1).',
                    'fix_action' => 'Redactar la resolución citando el convenio directamente, en vez de sustituirlo.',
                    'fix_surface' => 'Escalations (tarjeta)',
                    'fix_link' => null,
                ],
                'semantic_near_overlap' => fn (array $t) => [
                    'asked' => 'Un/a agente de RR.HH. intentó publicar una resolución como conocimiento.',
                    'found' => 'El texto propuesto se parece a pasajes del convenio vigente, pero no lo bastante para bloquear (ADR-0024, banda 2).',
                    'stopped_reason' => 'Una superposición plausible pide confirmación explícita humana antes de publicar — nunca un paso silencioso.',
                    'fix_action' => 'Leer los pasajes señalados y, si no hay solapamiento real, confirmar explícitamente para publicar.',
                    'fix_surface' => 'Escalations (tarjeta)',
                    'fix_link' => null,
                ],
                'semantic_compare_unavailable' => fn (array $t) => [
                    'asked' => 'Un/a agente de RR.HH. intentó publicar una resolución como conocimiento.',
                    'found' => 'La comparación semántica con el convenio vigente no se pudo ejecutar (fallo de servicio).',
                    'stopped_reason' => 'Una comparación no realizada no puede confirmar la ausencia de solapamiento — se pide confirmación explícita, nunca un paso silencioso.',
                    'fix_action' => 'Revisar manualmente contra el convenio vigente antes de confirmar la publicación.',
                    'fix_surface' => 'Escalations (tarjeta)',
                    'fix_link' => null,
                ],
                'semantic_no_text_to_compare' => fn (array $t) => [
                    'asked' => 'Un/a agente de RR.HH. intentó publicar una resolución como conocimiento.',
                    'found' => 'El convenio de este ámbito no tiene texto legible con el que comparar.',
                    'stopped_reason' => 'Sin texto de comparación, no se puede confirmar la ausencia de solapamiento — se pide confirmación explícita.',
                    'fix_action' => 'Cargar o verificar el texto legible del convenio de este ámbito.',
                    'fix_surface' => 'Documentos',
                    'fix_link' => $documentsLink(),
                ],
                'convert_blocked' => fn (array $t) => [
                    'asked' => 'Un/a agente de RR.HH. intentó convertir una tarjeta escalada en conocimiento publicado.',
                    'found' => 'El motivo de la escalada de esta tarjeta no está en el conjunto permitido para convertir (p. ej. un tema sensible nunca se publica).',
                    'stopped_reason' => 'Política de conversión por motivo (Sprint 6, ADR-0019, solo-restrictiva).',
                    'fix_action' => 'Responder a la persona directamente por otro canal; esta tarjeta no puede convertirse en conocimiento.',
                    'fix_surface' => 'Escalations (tarjeta)',
                    'fix_link' => null,
                ],
            ],
        ];
    }

    /** @return array{asked:string,found:string,stopped_reason:string,fix_action:string,fix_surface:string,fix_link:?string} */
    private static function genericFallback(string $reason): array
    {
        return [
            'asked' => 'Una consulta escalada.',
            'found' => "Motivo no reconocido por el explicador ({$reason}).",
            'stopped_reason' => 'Revisar el panel de trazas completo para el detalle exacto.',
            'fix_action' => 'Revisar manualmente la traza de este turno.',
            'fix_surface' => 'Escalations (tarjeta)',
            'fix_link' => null,
        ];
    }
}
