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
        // Sprint 10b, Correction-01: SMI/salario mínimo — a STATUTORY figure,
        // never a cell in the employee's own convenio table. Fires regardless
        // of whether a table/category/row exists (ChatService checks this
        // BEFORE ever calling SalaryAnswerService::answer()) — the other five
        // sub-outcomes above are all genuine absences of data; this one is not.
        'salary_coverage_gap.statutory_figure',
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
        // --- Sprint 8, Step 6 (plan.md §6.4) — quality-sample "wrong" verdicts,
        // one sub-outcome per `quality_samples.failure_kind` value ------------
        'quality_sample_wrong.wrong_scope',
        'quality_sample_wrong.wrong_figure',
        'quality_sample_wrong.stale_document',
        'quality_sample_wrong.unclear',
        'quality_sample_wrong.other',
        // --- Sprint 10a (ADR-0032) — the Estatuto fallback did NOT fire -------
        // One sub-outcome per cause of "a convenio text exists but is not being
        // served", because each needs a different person to do a different
        // thing. `never_ingested` is deliberately absent: that is the state in
        // which the fallback DOES fire, so it produces an answer, not a card.
        'estatuto_fallback_gap.expired_no_successor',
        'estatuto_fallback_gap.tagging_under_review',
        'estatuto_fallback_gap.scan_no_text',
        'estatuto_fallback_gap.not_yet_embedded',
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
            'estatuto_fallback_gap' => self::estatutoFallbackSubOutcome($trace),
            // Sprint 8, Step 6 (plan.md §6.4): the sub-outcome is already known
            // directly — the reviewer picked it (`quality_samples.failure_kind`)
            // — not detected from a floor/router trace shape like every other
            // reason here.
            'quality_sample_wrong' => $trace['quality_sample']['failure_kind'] ?? 'other',
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
            // Sprint 10b, Correction-01: checked FIRST — this is the one
            // sub-outcome that is not a data absence, so it must not fall
            // through to any of the coverage-gap notes below by accident.
            str_contains($note, 'statutory figure') => 'statutory_figure',
            str_contains($note, 'no convenio on profile') => 'no_convenio',
            str_contains($note, 'not-yet-effective') => 'future_only',
            str_contains($note, 'selected category not valid') => 'category_unresolved',
            str_contains($note, 'no salary row for this category') => 'no_row_for_category',
            default => 'no_table',
        };
    }

    /**
     * Sprint 10a (ADR-0032) — which flavour of "a convenio text exists but is
     * not being served" is this?
     *
     * Read straight off `prose_gap.reason_code`, which `ChatService` copies from
     * `CorpusCoverageService::proseGapReasonCode()` — the SAME code the
     * Cobertura screen shows for that convenio's prose cell. No pattern-matching
     * on note strings here: unlike the older reasons, this one has a real
     * structured field to read, so it reads it.
     *
     * `pending_embed` is checked FIRST and overrides the code. Cobertura reports
     * a document that has real text, verified tagging and no chunks as
     * SCAN_NO_TEXT — a catch-all, not a measurement — and acting on that here
     * would tell HR to re-source a convenio whose only problem is that
     * `chunks:embed` has not run yet. `CorpusCoverageService::proseGapEvidence()`
     * supplies the distinction as a structured flag rather than leaving this to
     * parse a detail string.
     */
    private static function estatutoFallbackSubOutcome(array $trace): string
    {
        if (($trace['prose_gap']['pending_embed'] ?? false) === true) {
            return 'not_yet_embedded';
        }

        return match ($trace['prose_gap']['reason_code'] ?? null) {
            CorpusCoverageService::REASON_EXPIRED_NO_SUCCESSOR => 'expired_no_successor',
            CorpusCoverageService::REASON_UNDER_REVIEW_SCOPE => 'tagging_under_review',
            CorpusCoverageService::REASON_SCAN_NO_TEXT => 'scan_no_text',
            default => 'not_yet_embedded',
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
        // Sprint 8 (plan.md §5.4): these builders now live in `AdminLinks`,
        // shared with `CorpusCoverageService`'s reason-code unblocking links,
        // rather than being redefined a second time for coverage gaps.
        $groupsLink = fn (?int $convenioId) => AdminLinks::groups($convenioId);
        $factLink = fn (?string $uuid) => AdminLinks::fact($uuid);
        $empLink = fn (?string $uuid) => AdminLinks::employee($uuid);
        $vocabLink = fn () => AdminLinks::vocabulary();
        $taggingLink = fn () => AdminLinks::tagging();
        $documentsLink = fn () => AdminLinks::documents();
        $guardrailsLink = fn () => AdminLinks::guardrails();
        $settingsLink = fn () => AdminLinks::settings();

        return [
            'sensitive_topic' => [
                'pattern_baseline' => fn (array $t) => [
                    'asked' => 'El empleado hizo una pregunta que coincide con un tema sensible (acoso, salud mental, o un procedimiento disciplinario/despido).',
                    'found' => 'No se buscó ningún dato — el sistema detiene automáticamente este tipo de consultas antes de intentar responder, para proteger la privacidad y la seguridad de la persona empleada.',
                    'stopped_reason' => 'Estos temas se derivan siempre a una persona, sin excepción, para proteger a la persona empleada.',
                    'fix_action' => 'Responder directamente a la persona empleada por otro canal; no hay nada que corregir en el sistema.',
                    'fix_surface' => 'ninguna — atención directa',
                    'fix_link' => null,
                ],
                'admin_blocked_topic' => fn (array $t) => [
                    'asked' => 'El empleado hizo una pregunta que coincide con un tema que un administrador ha marcado específicamente como bloqueado.',
                    'found' => 'No se buscó ningún dato — la pregunta contiene un texto que un administrador ha marcado como bloqueado para este tema.',
                    'stopped_reason' => 'Los bloqueos añadidos por un administrador se suman a la protección de base; nunca la debilitan.',
                    'fix_action' => 'Revisar en Guardarraíles si esta regla bloqueada sigue siendo correcta; ajustarla si está bloqueando preguntas legítimas.',
                    'fix_surface' => 'Guardarraíles (admin)',
                    'fix_link' => $guardrailsLink(),
                ],
            ],
            'off_domain' => [
                'legal_medical' => fn (array $t) => [
                    'asked' => 'El empleado pidió consejo legal o médico (asesoramiento jurídico, diagnóstico, tratamiento).',
                    'found' => 'No se buscó ningún dato — el sistema no da este tipo de consejo, tenga o no información relacionada en el convenio.',
                    'stopped_reason' => 'Es una limitación intencionada del sistema, siempre activa: nunca da asesoramiento legal ni médico.',
                    'fix_action' => 'Responder directamente o derivar a asesoría legal/médica externa; no hay nada que corregir en el sistema.',
                    'fix_surface' => 'ninguna — atención directa',
                    'fix_link' => null,
                ],
                'other_employee_data' => fn (array $t) => [
                    'asked' => 'El empleado preguntó por datos de OTRA persona (p. ej. cuánto gana/cobra alguien nombrado).',
                    'found' => 'No se buscó ningún dato — la pregunta nombra a un tercero; el sistema nunca responde sobre los datos de otra persona.',
                    'stopped_reason' => 'Proteger la privacidad de otras personas es una regla siempre activa, sin excepciones.',
                    'fix_action' => 'Nada que corregir; si la pregunta era legítima sobre el propio empleado, pedirle que la reformule sin nombrar a otra persona.',
                    'fix_surface' => 'ninguna — atención directa',
                    'fix_link' => null,
                ],
                'router_off_domain' => fn (array $t) => [
                    'asked' => 'La consulta se clasificó como ajena a RR.HH./convenio.',
                    'found' => 'No se buscó en la documentación disponible porque la consulta no trata sobre convenio, salario o RR.HH.',
                    'stopped_reason' => 'La consulta se clasificó automáticamente como fuera de este ámbito.',
                    'fix_action' => 'Si la pregunta SÍ era sobre convenio o RR.HH., revisar por qué se clasificó como ajena y avisar al equipo técnico si se repite.',
                    'fix_surface' => 'Revisión · Vocabulario/AI tagging',
                    'fix_link' => $vocabLink(),
                ],
                'admin_off_domain' => fn (array $t) => [
                    'asked' => 'El empleado hizo una pregunta que coincide con un tema que un administrador ha marcado como fuera de ámbito.',
                    'found' => 'No se buscó ningún dato — la pregunta contiene un texto marcado por un administrador como fuera de ámbito.',
                    'stopped_reason' => 'Los administradores pueden marcar temas adicionales como fuera de ámbito; esta pregunta coincidió con uno de ellos.',
                    'fix_action' => 'Revisar en Guardarraíles esta regla bloqueada; ajustarla si está bloqueando preguntas legítimas.',
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
                    'asked' => 'Una pregunta de convenio/RR.HH. para la que no se encontró ningún documento relacionado.',
                    'found' => 'No se encontró ningún contenido relevante en los documentos disponibles.',
                    'stopped_reason' => 'Sin contenido relevante que mostrar, el sistema nunca inventa una respuesta — se deriva a una persona.',
                    'fix_action' => 'Comprobar si existe un documento que cubra este tema y que esté correctamente cargado en el sistema; si no existe, añadirlo.',
                    'fix_surface' => 'Documentos',
                    'fix_link' => $documentsLink(),
                ],
                'weak_retrieval' => fn (array $t) => [
                    'asked' => 'Una pregunta de convenio/RR.HH. para la que se encontró contenido relacionado, pero ninguno lo bastante relevante.',
                    'found' => 'Se encontró contenido relacionado, pero ninguno lo bastante relevante como para basar una respuesta en él con confianza.',
                    'stopped_reason' => 'El contenido más parecido no es lo bastante relevante — el sistema prefiere no responder a arriesgar un dato incorrecto.',
                    'fix_action' => 'Revisar si el convenio correcto está cargado en el sistema y correctamente clasificado por tema; el contenido relevante puede estar mal dividido o mal etiquetado.',
                    'fix_surface' => 'Documentos',
                    'fix_link' => $documentsLink(),
                ],
                'citations_failed' => fn (array $t) => [
                    'asked' => 'Una pregunta de convenio/RR.HH. para la que sí había contenido relacionado disponible.',
                    'found' => 'El sistema no pudo respaldar ninguna parte de la respuesta con el contenido disponible (o no llegó a generar una respuesta).',
                    'stopped_reason' => 'Una respuesta que no se puede respaldar con el contenido disponible nunca se muestra a la persona empleada.',
                    'fix_action' => 'Puede ser un fallo puntual; si se repite con la misma pregunta, avisar al equipo técnico.',
                    'fix_surface' => 'AI tagging (revisión)',
                    'fix_link' => $taggingLink(),
                ],
                'figure_not_grounded' => fn (array $t) => [
                    'asked' => 'Una pregunta de convenio/RR.HH. cuya respuesta generada incluye una cifra.',
                    'found' => 'La respuesta incluía una cifra que no aparece en ningún documento consultado: '.implode(', ', $t['floor_decision']['figure_grounding']['ungrounded'] ?? ['(no identificada)']).'.',
                    'stopped_reason' => 'Una cifra que no se puede respaldar con ningún documento consultado nunca se muestra a la persona empleada.',
                    'fix_action' => 'Revisar el documento consultado; si el dato correcto existe en el convenio pero en otra parte del texto, puede ser un problema de cómo está dividido el documento.',
                    'fix_surface' => 'Documentos',
                    'fix_link' => $documentsLink(),
                ],
                'entailment_failed' => fn (array $t) => [
                    'asked' => 'Una pregunta de convenio/RR.HH. con una respuesta generada a partir de documentos consultados.',
                    'found' => 'Al menos una afirmación de la respuesta no está respaldada literalmente por el documento consultado: '.implode('; ', $t['floor_decision']['grounding']['ungrounded'] ?? ['(no especificado)']).'.',
                    'stopped_reason' => 'Cualquier afirmación que no se pueda respaldar palabra por palabra con el documento se deriva a una persona, en vez de mostrarse.',
                    'fix_action' => 'Leer la afirmación y el documento consultado; si el convenio SÍ lo dice, puede tratarse de una redacción ambigua que dificulta la comprobación automática.',
                    'fix_surface' => 'AI tagging (revisión)',
                    'fix_link' => $taggingLink(),
                ],
                'grounding_truncated' => fn (array $t) => [
                    'asked' => 'Una pregunta de convenio/RR.HH. con una respuesta generada a partir de documentos consultados.',
                    'found' => 'La comprobación automática de esta respuesta no llegó a completarse — no significa que la respuesta sea incorrecta, solo que no se pudo confirmar a tiempo.',
                    'stopped_reason' => 'Por precaución, una comprobación incompleta se trata igual que una fallida, aunque no sea un error del dato en sí, sino un problema técnico puntual.',
                    'fix_action' => 'Volver a intentar la misma pregunta; si el problema persiste, avisar al equipo técnico.',
                    'fix_surface' => 'ninguna — probable fallo transitorio',
                    'fix_link' => null,
                ],
                'aggregation' => fn (array $t) => [
                    'asked' => 'El empleado preguntó por un TOTAL agregado de varios tipos de días libres (p. ej. "días libres en total").',
                    'found' => 'No se buscó una respuesta automática — sumar varios tipos de permiso distintos no es un dato único que se pueda garantizar con exactitud.',
                    'stopped_reason' => 'Las preguntas que piden un total sumando varios conceptos distintos se derivan siempre a una persona, para evitar un cálculo automático incorrecto.',
                    'fix_action' => 'Responder desglosando cada tipo de día libre por separado (vacaciones, asuntos propios, permisos específicos).',
                    'fix_surface' => 'ninguna — respuesta manual desglosada',
                    'fix_link' => null,
                ],
                'cross_path' => fn (array $t) => [
                    'asked' => 'Una pregunta compuesta que combina una parte salarial con otra parte no salarial.',
                    'found' => 'La parte salarial se detectó, pero la parte no salarial no se respondió — para no descartarla en silencio, ambas se derivan juntas.',
                    'stopped_reason' => 'Responder solo la parte salarial habría ignorado silenciosamente la otra mitad de la pregunta, así que se deriva completa a una persona.',
                    'fix_action' => 'Responder ambas partes por separado: la cifra salarial (consultable en el chat) y el tema no salarial.',
                    'fix_surface' => 'ninguna — respuesta manual en dos partes',
                    'fix_link' => null,
                ],
                'answer_model_not_configured' => fn (array $t) => [
                    'asked' => 'Una pregunta de convenio/RR.HH. para la que sí había contenido relevante disponible.',
                    'found' => 'No se generó ninguna respuesta — el sistema de generación de respuestas no está configurado todavía.',
                    'stopped_reason' => 'Sin esa configuración, el sistema nunca inventa una respuesta; deriva la consulta a una persona.',
                    'fix_action' => 'Completar la configuración del sistema de respuestas en Ajustes.',
                    'fix_surface' => 'Ajustes (admin)',
                    'fix_link' => $settingsLink(),
                ],
                'provider_error' => fn (array $t) => [
                    'asked' => 'Una pregunta de convenio/RR.HH. para la que sí había contenido relevante disponible.',
                    'found' => 'El servicio que genera las respuestas no estuvo disponible en el momento de la consulta.',
                    'stopped_reason' => 'Cuando el servicio de respuestas falla, la consulta se deriva siempre a una persona, en vez de mostrar una respuesta sin comprobar.',
                    'fix_action' => 'Comprobar el estado del servicio de respuestas y su configuración; es probable que sea un fallo puntual.',
                    'fix_surface' => 'Ajustes (admin)',
                    'fix_link' => $settingsLink(),
                ],
                'unspecified' => fn (array $t) => [
                    'asked' => 'Una pregunta de convenio/RR.HH.',
                    'found' => 'El motivo exacto de la derivación no se ha podido determinar automáticamente.',
                    'stopped_reason' => 'Se derivó por baja confianza en la respuesta generada; conviene revisar el detalle completo de esta consulta.',
                    'fix_action' => 'Revisar el detalle completo de este turno en el panel de trazas.',
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
                    'stopped_reason' => 'El convenio manda siempre; un desacuerdo real nunca se resuelve automáticamente a favor del dato — se deriva a una persona.',
                    'fix_action' => 'Revisar el dato de referencia: si está desactualizado o mal capturado, corregirlo o marcarlo como pendiente de revisión.',
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
                    'stopped_reason' => 'Sin una tabla salarial cargada, el sistema nunca adivina una cifra.',
                    'fix_action' => 'Cargar la tabla salarial de este convenio; si el origen es un PDF, puede necesitar conversión previa por el equipo técnico.',
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
                    'stopped_reason' => 'Una categoría que no pertenece a este convenio nunca se acepta.',
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
                // Sprint 10b, Correction-01: SMI/salario mínimo. Distinct from
                // the four sub-outcomes above — this is NOT a coverage gap in
                // the employee's own table (one may well exist, with a row for
                // their category); the question asks for a different, national
                // figure that table was never going to contain.
                'statutory_figure' => fn (array $t) => [
                    'asked' => 'El empleado preguntó por el SMI (salario mínimo interprofesional) u otra cifra salarial estatutaria general.',
                    'found' => 'No se consultó la tabla salarial del empleado — el SMI es una cifra legal general que fija el Estado, no un dato de la tabla de ningún convenio concreto, así que su categoría o tabla (exista o no) nunca iba a contener la respuesta.',
                    'stopped_reason' => 'Responder con la cifra de la tabla del empleado habría sido una respuesta real pero equivocada (no responde a lo que se preguntó); el sistema nunca sustituye una cifra estatutaria por una cifra de convenio.',
                    'fix_action' => 'Responder directamente con el SMI vigente (cifra pública, no requiere revisar el perfil del empleado) o derivar a la fuente oficial (BOE/SMI anual).',
                    'fix_surface' => 'ninguna — cifra pública, respuesta manual',
                    'fix_link' => null,
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
                    'found' => 'Existe un dato propuesto, pero ninguna persona lo ha verificado todavía.',
                    'stopped_reason' => 'Un dato que todavía no ha verificado una persona nunca se muestra al empleado.',
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
                    'stopped_reason' => 'Sin un grupo asignado, el sistema nunca adivina cuál de los datos por grupo le corresponde.',
                    'fix_action' => 'Asignar el grupo/sub-área del empleado en el Directorio.',
                    'fix_surface' => 'Directorio',
                    'fix_link' => $empLink($t['profile']['employee_uuid'] ?? null),
                ],
                'group_structure_not_approved' => fn (array $t) => [
                    'asked' => 'El empleado preguntó por un tema cuyo dato de referencia está segmentado por grupo/categoría en este convenio.',
                    'found' => 'El grupo asignado al empleado todavía no existe o no ha sido aprobado por una persona.',
                    'stopped_reason' => 'Un grupo sin aprobar se trata igual que si no existiera, hasta que una persona lo confirme.',
                    'fix_action' => 'Aprobar la estructura de grupos de este convenio en la pestaña Groups.',
                    'fix_surface' => 'Groups (revisión)',
                    'fix_link' => $groupsLink($t['profile']['convenio_id'] ?? null),
                ],
                'subarea_not_recorded' => fn (array $t) => [
                    'asked' => 'El empleado preguntó por un tema cuyo dato de referencia está segmentado por sub-área dentro de su grupo.',
                    'found' => 'El dato está vinculado a una sub-área específica del grupo del empleado, pero no sabemos en qué sub-área está el empleado.',
                    'stopped_reason' => 'Responder al nivel del grupo completo sería menos preciso que la información disponible, así que se deriva a una persona en vez de adivinar.',
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
                    'stopped_reason' => 'Un conflicto genuino nunca se resuelve automáticamente ni al azar — se deriva a una persona para decidir cuál es correcto.',
                    'fix_action' => 'Revisar ambos datos verificados y corregir/retirar el que esté desactualizado o mal capturado.',
                    'fix_surface' => 'Reference facts (revisión)',
                    'fix_link' => $factLink(null),
                ],
            ],
            'publish' => [
                'topic_scope_conflict' => fn (array $t) => [
                    'asked' => 'Un/a agente de RR.HH. intentó publicar una resolución como conocimiento oficial.',
                    'found' => 'Ya existe un convenio vigente para este ámbito, pero el tema todavía no está etiquetado.',
                    'stopped_reason' => 'Sin esa etiqueta, el sistema bloquea siempre la publicación, antes de cualquier otra comprobación.',
                    'fix_action' => 'Etiquetar el tema del convenio activo de este ámbito.',
                    'fix_surface' => 'AI tagging (revisión)',
                    'fix_link' => $taggingLink(),
                ],
                'semantic_overlap' => fn (array $t) => [
                    'asked' => 'Un/a agente de RR.HH. intentó publicar una resolución como conocimiento.',
                    'found' => 'El texto propuesto coincide casi palabra por palabra con el convenio oficial vigente en el mismo punto.',
                    'stopped_reason' => 'El convenio manda siempre; una resolución interna nunca puede sustituirlo ni prevalecer sobre él.',
                    'fix_action' => 'Redactar la resolución citando el convenio directamente, en vez de sustituirlo.',
                    'fix_surface' => 'Escalations (tarjeta)',
                    'fix_link' => null,
                ],
                'semantic_near_overlap' => fn (array $t) => [
                    'asked' => 'Un/a agente de RR.HH. intentó publicar una resolución como conocimiento.',
                    'found' => 'El texto propuesto se parece a partes del convenio vigente, pero no lo bastante como para bloquear la publicación automáticamente.',
                    'stopped_reason' => 'Una coincidencia parcial pide confirmación explícita de una persona antes de publicar — nunca un paso silencioso.',
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
                    'stopped_reason' => 'Existe una política que decide qué motivos de escalada pueden convertirse en conocimiento publicado; este motivo no está permitido.',
                    'fix_action' => 'Responder a la persona directamente por otro canal; esta tarjeta no puede convertirse en conocimiento.',
                    'fix_surface' => 'Escalations (tarjeta)',
                    'fix_link' => null,
                ],
            ],
            // Sprint 8, Step 6 (plan.md §6.4): opened when a monthly quality
            // sample is reviewed with verdict='wrong' — one sub-outcome per
            // `failure_kind`, `fix_link` following the same 3-destination
            // scheme (fact / documents / directory) the plan text names.
            'quality_sample_wrong' => [
                'wrong_scope' => fn (array $t) => [
                    'asked' => 'Una respuesta ya enviada al empleado, revisada en el muestreo mensual de calidad.',
                    'found' => 'La respuesta usó un ámbito equivocado (convenio, grupo o sub-área distinto al que corresponde a este empleado).',
                    'stopped_reason' => 'El ámbito de la respuesta no coincide con el perfil real del empleado — detectado en revisión humana, no automáticamente en el momento de responder.',
                    'fix_action' => 'Revisar y corregir el convenio/grupo/sub-área asignado al empleado en el Directorio.',
                    'fix_surface' => 'Directorio',
                    'fix_link' => $empLink($t['quality_sample']['employee_uuid'] ?? null),
                ],
                'wrong_figure' => fn (array $t) => [
                    'asked' => 'Una respuesta ya enviada al empleado, revisada en el muestreo mensual de calidad.',
                    'found' => 'La respuesta citaba una cifra incorrecta.',
                    'stopped_reason' => 'Una cifra incorrecta detectada en revisión humana; la comprobación automática en el momento de responder no lo detectó.',
                    'fix_action' => 'Revisar y corregir el dato de referencia o la tabla salarial de origen de esta cifra.',
                    'fix_surface' => 'Reference facts (revisión)',
                    'fix_link' => $factLink($t['quality_sample']['fact_uuid'] ?? null),
                ],
                'stale_document' => fn (array $t) => [
                    'asked' => 'Una respuesta ya enviada al empleado, revisada en el muestreo mensual de calidad.',
                    'found' => 'La respuesta se basó en un documento que ya no está vigente (una versión anterior del convenio, por ejemplo).',
                    'stopped_reason' => 'El documento de origen quedó desactualizado sin que el sistema lo marcara — detectado en revisión humana.',
                    'fix_action' => 'Cargar/activar la versión vigente del documento y retirar o marcar como caducada la versión anterior.',
                    'fix_surface' => 'Documentos',
                    'fix_link' => $documentsLink(),
                ],
                'unclear' => fn (array $t) => [
                    'asked' => 'Una respuesta ya enviada al empleado, revisada en el muestreo mensual de calidad.',
                    'found' => 'La respuesta no fue clara o fue difícil de entender para la persona empleada, aunque el dato en sí no fuera necesariamente incorrecto.',
                    'stopped_reason' => 'Claridad insuficiente detectada en revisión humana, no un fallo de exactitud del dato.',
                    'fix_action' => 'Revisar el documento/dato de origen; si el texto de origen es en sí ambiguo, puede necesitar una redacción más clara o una mejor división del documento.',
                    'fix_surface' => 'Documentos',
                    'fix_link' => $documentsLink(),
                ],
                'other' => fn (array $t) => [
                    'asked' => 'Una respuesta ya enviada al empleado, revisada en el muestreo mensual de calidad.',
                    'found' => 'La persona revisora marcó la respuesta como incorrecta por un motivo que no encaja en las demás categorías.',
                    'stopped_reason' => 'Motivo libre, registrado en la nota de la revisión.',
                    'fix_action' => 'Leer la nota de la persona revisora para entender el motivo exacto y decidir la corrección.',
                    'fix_surface' => 'Escalations (tarjeta)',
                    'fix_link' => null,
                ],
            ],

            // --- Sprint 10a (ADR-0032): the fallback deliberately did NOT fire.
            // The employee's convenio has no retrievable prose, but a convenio
            // text DOES exist in the system, so answering from the Estatuto
            // would present the national minimum as if it were their agreement.
            // All four say the same thing to the employee (one neutral message)
            // and four different things to HR.
            'estatuto_fallback_gap' => [
                'expired_no_successor' => fn (array $t) => [
                    'asked' => 'El empleado preguntó por una condición regulada normalmente en su convenio colectivo.',
                    'found' => 'El convenio de este empleado está vencido y no consta un convenio posterior que lo sustituya. Su texto ya no se usa para responder.',
                    'stopped_reason' => 'Un convenio vencido suele seguir aplicándose en ultraactividad (art. 86.4 del Estatuto de los Trabajadores) hasta que se negocie uno nuevo, así que responder con el mínimo legal del Estatuto podría dar al empleado una condición peor que la que realmente le corresponde. El sistema prefiere escalar antes que arriesgarse a eso.',
                    'fix_action' => 'Conseguir el texto del convenio vigente y cargarlo; si no existe sucesor, confirmar si sigue en ultraactividad y reactivar el texto anterior.',
                    'fix_surface' => 'Documentos',
                    'fix_link' => $documentsLink(),
                ],
                'tagging_under_review' => fn (array $t) => [
                    'asked' => 'El empleado preguntó por una condición regulada normalmente en su convenio colectivo.',
                    'found' => 'Existe el texto del convenio de este empleado, pero su etiquetado todavía no está verificado, así que aún no se ha indexado para búsqueda.',
                    'stopped_reason' => 'El texto existe: responder con el mínimo legal del Estatuto sería peor que esperar a que el convenio propio esté disponible.',
                    'fix_action' => 'Verificar el etiquetado de este documento para que pase a indexarse.',
                    'fix_surface' => 'Etiquetado',
                    'fix_link' => $taggingLink(),
                ],
                'scan_no_text' => fn (array $t) => [
                    'asked' => 'El empleado preguntó por una condición regulada normalmente en su convenio colectivo.',
                    'found' => 'El convenio de este empleado está cargado solo como escaneo de imagen, sin texto extraíble, así que no puede buscarse.',
                    'stopped_reason' => 'El convenio existe pero es ilegible para el sistema; responder con el mínimo legal del Estatuto ocultaría ese problema en lugar de resolverlo.',
                    'fix_action' => 'Conseguir una versión del convenio con texto (no escaneada) o pedir al equipo técnico el reconocimiento de texto (OCR) del documento actual.',
                    'fix_surface' => 'Documentos',
                    'fix_link' => $documentsLink(),
                ],
                'not_yet_embedded' => fn (array $t) => [
                    'asked' => 'El empleado preguntó por una condición regulada normalmente en su convenio colectivo.',
                    'found' => 'El texto del convenio de este empleado está cargado y activo, pero su indexado para búsqueda todavía no ha terminado.',
                    'stopped_reason' => 'Es un estado transitorio de la carga del documento, no un hueco real de cobertura: el texto llegará. El sistema escala en lugar de responder con el mínimo legal mientras tanto.',
                    'fix_action' => 'Esperar a que termine el indexado; si no avanza en unas horas, avisar al equipo técnico.',
                    'fix_surface' => 'Documentos',
                    'fix_link' => $documentsLink(),
                ],
            ],
        ];
    }

    /** @return array{asked:string,found:string,stopped_reason:string,fix_action:string,fix_surface:string,fix_link:?string} */
    private static function genericFallback(string $reason): array
    {
        return [
            'asked' => 'Una consulta escalada.',
            'found' => 'Este motivo de escalada todavía no tiene una explicación específica en el sistema.',
            'stopped_reason' => 'Conviene revisar el detalle completo de esta consulta para entender exactamente qué ocurrió.',
            'fix_action' => 'Revisar manualmente el detalle completo de este turno en el panel de trazas.',
            'fix_surface' => 'Escalations (tarjeta)',
            'fix_link' => null,
        ];
    }
}
