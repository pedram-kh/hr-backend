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
     * Salary / wage / retribution questions (Correction-02). Salary lives in the
     * structured `salary_tables` (ADR-0006) and is NEVER embedded as a vector
     * chunk — embeddings mangle tabular numbers and a salary figure must be exact.
     * In 2b-1 there is no salary path, and convenio PDFs ingested in 2a contain
     * wage-table pages that DID get chunked as prose; without this guard a salary
     * question can retrieve such a chunk and surface a year-misattributed figure
     * (the adversarial probe Q5 reported 2024 = 1.330,80 € when the real 2024 row
     * is 1.244,74 €). So salary questions escalate (`salary_not_in_chat`) BEFORE
     * any hr-ai call. The grounded salary answer (from `salary_tables` via SQL) is
     * Sprint 2b-2, which supersedes this guard.
     *
     * Conservative-by-design and additive: like the sensitive baseline it can only
     * cause escalation, never a weaker answer. Patterns are deliberately narrow on
     * the bare verb "paga" (so "¿la empresa me paga el gimnasio?" is NOT a salary
     * question) but broad on the salary nouns.
     *
     * @var list<string>
     */
    private const SALARY_PATTERNS = [
        '/\b(salario|sueldo|n[oó]mina|retribuci[oó]n|remuneraci[oó]n)\b/iu',
        '/\btablas?\s+salarial(es)?\b/iu',
        '/\bpagas?\s+extra/iu',
        // "¿cuánto gano / gana un limpiador / cobro / me pagan / se cobra…?" — the
        // bare verb "paga" is only matched when preceded by "cuánto", so a benefit
        // question ("¿me paga el gimnasio?") does not trip it.
        '/\bcu[aá]nto\s+(gano|gana|gan[aá]is|cobro|cobra|cobr[aá]is|ingreso|ingresa|me\s+pagan?|se\s+(gana|cobra|paga))\b/iu',
    ];

    /**
     * Check the raw question. Returns the escalation reason + matched rule if the
     * baseline fires, else fired = false.
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

        // Other-employee data runs BEFORE the salary guard: "¿cuánto gana Pedro?"
        // is a privacy probe (off_domain / other_employee_data), not a generic
        // salary question.
        foreach (self::OTHER_EMPLOYEE_PATTERNS as $pattern) {
            if (preg_match($pattern, $question)) {
                return ['fired' => true, 'reason' => 'off_domain', 'rule' => 'other_employee_data'];
            }
        }

        foreach (self::SALARY_PATTERNS as $pattern) {
            if (preg_match($pattern, $question)) {
                return ['fired' => true, 'reason' => 'salary_not_in_chat', 'rule' => 'salary_topic'];
            }
        }

        return ['fired' => false, 'reason' => null, 'rule' => null];
    }
}
