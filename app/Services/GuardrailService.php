<?php

namespace App\Services;

/**
 * The HARDCODED guardrail baseline (architecture.md §7, ADR-0015).
 *
 * This runs deterministically in hr-backend BEFORE any hr-ai call. A firing
 * question short-circuits the pipeline and is NEVER sent to the external
 * provider — a safety guarantee and a privacy bonus (a harassment / mental-health
 * message never leaves Sedena's servers).
 *
 * Admins CANNOT weaken this baseline (the config UI is Sprint 6, additive-only).
 * It fires REGARDLESS of confidence or retrieval quality.
 *
 * The patterns are intentionally CONSERVATIVE (broad). They will escalate some
 * legitimately-answerable questions (e.g. "preaviso por despido en mi convenio").
 * That is the correct default for a legal-weight tool: a false escalation is far
 * cheaper than synthesising advice on a live disciplinary/harassment matter. Do
 * not narrow these patterns.
 */
class GuardrailService
{
    /**
     * Sensitive topics → IMMEDIATE escalation, before synthesis, never sent to
     * the provider. Maps a normalized regex to its escalation reason.
     *
     * @var array<string, string>
     */
    private const SENSITIVE_PATTERNS = [
        // Harassment / acoso
        '/\b(acoso|acosad|hostigamiento|intimidaci[oó]n|abuso|mobbing|denunci(a|ar|o))\b/iu' => 'sensitive_topic',
        // Mental health
        '/\b(salud mental|ansiedad|depresi[oó]n|burnout|estr[eé]s severo|baja psicol[oó]gica|psic[oó]log|psiquiatr)\b/iu' => 'sensitive_topic',
        // Disciplinary / termination
        '/\b(expediente disciplinario|sanci[oó]n disciplinaria|despido|despid(e|i|o)|cese|carta de despido|procedimiento disciplinario|finiquito)\b/iu' => 'sensitive_topic',
    ];

    /**
     * No legal / medical advice. Escalated as off_domain (the bot does not give
     * legal or medical interpretation even where chunks exist).
     *
     * @var list<string>
     */
    private const LEGAL_MEDICAL_PATTERNS = [
        '/\b(consejo legal|asesoramiento jur[ií]dico|abogad|demanda judicial|recurso legal|denuncia ante|juzgado)\b/iu',
        '/\b(diagn[oó]stico m[eé]dico|consejo m[eé]dico|tratamiento m[eé]dico|medicaci[oó]n|s[ií]ntomas)\b/iu',
    ];

    /**
     * No other-employee data. Best-effort heuristic in 2b-1: an explicit
     * "¿cuánto gana/cobra [Nombre]?" style probe about a third party. The hard
     * enforcement (role-scoped access to chat history) is Sprint 5.
     *
     * @var list<string>
     */
    private const OTHER_EMPLOYEE_PATTERNS = [
        // "cuánto gana/cobra/cobró Juan ..." — a salary/contract probe naming a
        // person. The trailing name token stays CASE-SENSITIVE (a proper noun, so
        // "un técnico" doesn't trip it); the leading verb/keyword allows a
        // sentence-initial capital so "¿Cuánto gana Pedro?" still matches.
        '/[Cc]u[aá]nto\s+(gana|cobra|cobr[oó]|ingresa)\s+[A-ZÁÉÍÓÚÑ][a-záéíóúñ]+/u',
        '/[Ss](alario|ueldo)\s+de\s+[A-ZÁÉÍÓÚÑ][a-záéíóúñ]+/u',
        '/[Nn][oó]mina\s+de\s+[A-ZÁÉÍÓÚÑ][a-záéíóúñ]+/u',
    ];

    /**
     * Check the raw question. Returns the escalation reason + matched rule if the
     * baseline fires, else fired = false.
     *
     * Sprint 2b-2: the salary patterns are NO LONGER here. The Correction-02
     * salary guard (which blanket-escalated salary with `salary_not_in_chat`) is
     * SUPERSEDED — salary is now ANSWERED in chat from `salary_tables` via SQL.
     * The deterministic salary detection moved to `RouterService` as a
     * high-precision PRE-CLASSIFIER that routes obvious salary to the SQL path
     * WITHOUT escalating (ADR-0016). The other-employee privacy rule still fires
     * HERE, first, so "¿cuánto gana Pedro García?" is escalated as
     * `other_employee_data` and never reaches the router or the salary path.
     *
     * @return array{fired:bool, reason:?string, rule:?string}
     */
    public function check(string $question): array
    {
        foreach (self::SENSITIVE_PATTERNS as $pattern => $reason) {
            if (preg_match($pattern, $question)) {
                return ['fired' => true, 'reason' => $reason, 'rule' => 'sensitive_topic'];
            }
        }

        foreach (self::LEGAL_MEDICAL_PATTERNS as $pattern) {
            if (preg_match($pattern, $question)) {
                return ['fired' => true, 'reason' => 'off_domain', 'rule' => 'legal_medical'];
            }
        }

        // Other-employee data: "¿cuánto gana Pedro?" is a privacy probe
        // (off_domain / other_employee_data), NOT a generic salary question — and
        // it fires in the guardrail baseline, BEFORE the router, so a named third
        // party never reaches the salary path.
        foreach (self::OTHER_EMPLOYEE_PATTERNS as $pattern) {
            if (preg_match($pattern, $question)) {
                return ['fired' => true, 'reason' => 'off_domain', 'rule' => 'other_employee_data'];
            }
        }

        return ['fired' => false, 'reason' => null, 'rule' => null];
    }
}
