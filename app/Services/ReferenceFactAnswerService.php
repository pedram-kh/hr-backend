<?php

namespace App\Services;

use App\Models\Document;
use App\Models\Employee;
use App\Models\ReferenceFact;
use Illuminate\Support\Carbon;

/**
 * Reference-fact-in-chat (Sprint 7c Phase 1, ADR-0023) — the salary sibling.
 *
 * Mirrors SalaryAnswerService EXACTLY, generalized from the salary grid to a
 * topic-scoped structured fact: resolve scope → query the verified, in-scope,
 * in-validity `reference_facts` row → return the EXACT `value` (+ raw_values) →
 * cite the source document with `chunk_id = null` (the salary citation shape) →
 * record `authority_used = structured_reference` → SKIP /ground (a quoted
 * verified value, nothing generated to entail, exactly as salary skips it).
 *
 * SAFETY SPINE (ADR-0020/0021, tested hardest): ONLY a `status = 'verified'`
 * fact is ever quoted. An unverified / rejected / out-of-validity / future-only
 * fact is NEVER quoted — the turn escalates `reference_fact_coverage_gap`.
 *
 * SCOPE RESOLUTION (Q2) — MOST-SPECIFIC, ELSE ESCALATE, never guess:
 *   1. a fact matching the employee's job_category_id (the finest scope), else
 *   2. a fact matching the employee's CONFIDENTLY-resolved group, else
 *   3. the convenio-wide fact (null job_category_id AND null group_label), else
 *   4. escalate — a confident wrong-group answer is the exact 7b-2 failure mode;
 *      a coverage-gap escalation is the safe outcome.
 *
 * TWO VERIFIED MATCHES in a tier (Q-safe rule): most-recent validity wins; a
 * genuine same-validity conflict (differing value) escalates. Rich conflict /
 * version RESOLUTION is Sprint 7d — this is the deterministic safe rule only.
 */
class ReferenceFactAnswerService
{
    public const OUTCOME_ANSWER = 'answer';

    public const OUTCOME_ESCALATE = 'escalate';

    public const ESCALATION_REASON = 'reference_fact_coverage_gap';

    /** Surfaced when no verified, in-scope, in-validity fact answers (coverage gap). */
    public const COVERAGE_GAP_MESSAGE = 'Tengo información de referencia sobre ese tema, pero todavía '
        .'no puedo confirmártela con exactitud para tu caso concreto (tu categoría o grupo, o la fecha), '
        .'y no quiero darte un dato que no esté verificado para tu situación. Te derivo con una persona '
        .'del equipo de Recursos Humanos.';

    /**
     * Answer a reference-fact question from the verified fact, or escalate.
     *
     * @return array{outcome:string, answer:string, citations:list<array<string,mixed>>, escalation_reason:?string, reference_fact:array<string,mixed>}
     */
    public function answer(Employee $employee, int $topicId, Carbon $asOfDate): array
    {
        $employee->loadMissing(['convenio', 'jobCategory']);
        $convenio = $employee->convenio;

        $rf = [
            'convenio_id' => $convenio?->id,
            'topic_id' => $topicId,
            'job_category_id' => $employee->job_category_id,
            'group_label' => null,
            'as_of_date' => $asOfDate->toDateString(),
            'fact_id' => null,
            'validity_selection' => null,
            'value' => null,
            'authority_used' => ReferenceFact::AUTHORITY_LEVEL,
        ];

        if (! $convenio) {
            return $this->escalate($rf, 'no convenio on profile');
        }

        // --- The verified, in-scope, in-validity candidate set (Q3) -------------
        // ONLY status = 'verified' (the inert-until-verified gate, ADR-0020/0021).
        // "validity contains as-of": (start null OR ≤ as-of) AND (end null OR ≥ as-of).
        // A future-only fact (start > as-of) is excluded here → coverage gap (never
        // quote a not-yet-effective fact — the salary future_only precedent).
        $asOf = $asOfDate->toDateString();
        $candidates = ReferenceFact::query()
            ->where('convenio_id', $convenio->id)
            ->where('topic_id', $topicId)
            ->where('status', 'verified')
            ->where(fn ($q) => $q->whereNull('validity_start')->orWhere('validity_start', '<=', $asOf))
            ->where(fn ($q) => $q->whereNull('validity_end')->orWhere('validity_end', '>=', $asOf))
            ->get();

        if ($candidates->isEmpty()) {
            return $this->escalate($rf, 'no verified in-scope in-validity fact (only unverified / out-of-validity / future-only, or none)');
        }

        // --- Q2 resolution: most-specific, ELSE ESCALATE (never guess a group) --
        // Tier 1 — exact job_category_id (the finest scope).
        if ($employee->job_category_id !== null) {
            $byCategory = $candidates->where('job_category_id', $employee->job_category_id)->values();
            if ($byCategory->isNotEmpty()) {
                return $this->resolveAndAnswer($byCategory, $rf, 'job_category', null);
            }
        }

        // Tier 2 — the employee's CONFIDENTLY-resolved group (else skip — never guess).
        $groupCode = $this->resolveEmployeeGroupCode($employee);
        if ($groupCode !== null) {
            $byGroup = $candidates->filter(fn (ReferenceFact $f) => $this->factMatchesGroup($f, $groupCode))->values();
            if ($byGroup->isNotEmpty()) {
                return $this->resolveAndAnswer($byGroup, $rf, 'group_label', $groupCode);
            }
        }

        // Tier 3 — the convenio-wide fact (null job_category_id AND null group_label).
        $wide = $candidates->filter(fn (ReferenceFact $f) => $f->job_category_id === null && $f->group_label === null)->values();
        if ($wide->isNotEmpty()) {
            return $this->resolveAndAnswer($wide, $rf, 'convenio_wide', null);
        }

        // Tier 4 — verified facts exist, but only per-group/per-category ones that
        // DON'T apply to this employee's resolvable scope (or the group can't be
        // confidently resolved). Escalate — NEVER answer from a guessed group.
        return $this->escalate($rf, 'only per-group/per-category facts exist; employee scope does not confidently match one (group unresolved or different group) — never guess');
    }

    /**
     * Apply the two-verified-match safe rule (most-recent validity; else escalate
     * on a genuine same-validity conflict) and compose the answer for the chosen
     * fact. `$matchKind` ∈ job_category | group_label | convenio_wide.
     *
     * @param  \Illuminate\Support\Collection<int, ReferenceFact>  $tier
     * @param  array<string,mixed>  $rf
     * @return array{outcome:string, answer:string, citations:list<array<string,mixed>>, escalation_reason:?string, reference_fact:array<string,mixed>}
     */
    private function resolveAndAnswer($tier, array $rf, string $matchKind, ?string $groupCode): array
    {
        [$fact, $selection] = $this->selectMostRecent($tier);

        if ($fact === null) {
            $rf['validity_selection'] = $selection; // 'ambiguous_conflict'
            $rf['match_kind'] = $matchKind;

            return $this->escalate($rf, 'two verified facts with the same most-recent validity and differing values — escalate, do not blend (resolution is 7d)');
        }

        $rf['fact_id'] = $fact->id;
        $rf['value'] = $fact->value;
        $rf['validity_selection'] = $selection; // 'single' | 'most_recent_validity'
        $rf['match_kind'] = $matchKind;
        $rf['group_label'] = $matchKind === 'group_label' ? ($fact->group_label ?? $groupCode) : $fact->group_label;
        $rf['validity_start'] = $fact->validity_start?->toDateString();
        $rf['validity_end'] = $fact->validity_end?->toDateString();
        $rf['outcome'] = 'answer';

        return [
            'outcome' => self::OUTCOME_ANSWER,
            'answer' => $this->composeAnswer($fact),
            'citations' => $this->referenceFactCitation($fact),
            'escalation_reason' => null,
            'reference_fact' => $rf,
        ];
    }

    /**
     * The two-verified-match safe rule: prefer the most-recent validity_start (a
     * newer version supersedes; null = oldest/unknown). If the most-recent group
     * still holds >1 fact with DIFFERING values → ambiguous, escalate (7d
     * resolves). Returns [fact|null, selection].
     *
     * @param  \Illuminate\Support\Collection<int, ReferenceFact>  $facts
     * @return array{0: ?ReferenceFact, 1: string}
     */
    private function selectMostRecent($facts): array
    {
        if ($facts->count() === 1) {
            return [$facts->first(), 'single'];
        }

        // Sort by validity_start desc; a null start sorts last (oldest/unknown).
        $sorted = $facts->sortByDesc(fn (ReferenceFact $f) => $f->validity_start?->timestamp ?? PHP_INT_MIN)->values();
        $top = $sorted->first();
        $topStart = $top->validity_start?->timestamp ?? PHP_INT_MIN;

        // Any OTHER fact sharing the top validity_start with a DIFFERENT value → conflict.
        $sameStartConflict = $sorted->slice(1)->contains(
            fn (ReferenceFact $f) => ($f->validity_start?->timestamp ?? PHP_INT_MIN) === $topStart && $f->value !== $top->value
        );

        if ($sameStartConflict) {
            return [null, 'ambiguous_conflict'];
        }

        return [$top, 'most_recent_validity'];
    }

    /** The employee's group code (e.g. "1"), or null when it can't be resolved. */
    private function resolveEmployeeGroupCode(Employee $employee): ?string
    {
        $code = $employee->jobCategory?->group_code;
        $code = $code !== null ? trim((string) $code) : '';

        return $code === '' ? null : $code;
    }

    /**
     * True when the fact's free-text `group_label` confidently names the
     * employee's group code as a standalone token ("Grupo 1", "Grupos 1 y 2" both
     * match code "1"; "Grupo 10" does NOT match code "1"). Conservative — a label
     * that doesn't clearly name the group is not a match (Tier 4 escalates).
     */
    private function factMatchesGroup(ReferenceFact $fact, string $groupCode): bool
    {
        if ($fact->group_label === null || $fact->group_label === '') {
            return false;
        }

        return (bool) preg_match('/(?<!\d)'.preg_quote($groupCode, '/').'(?!\d)/u', $fact->group_label);
    }

    /** Compose the exact, quoted reference-fact answer (value + a raw breakdown if useful). */
    private function composeAnswer(ReferenceFact $fact): string
    {
        $value = trim((string) $fact->value);
        $breakdown = $this->renderRawValues($fact->raw_values);
        $detail = $breakdown !== '' ? " ({$breakdown})" : '';

        return "Según el dato de referencia verificado de tu convenio: {$value}{$detail}. "
            .'(Dato estructurado exacto, citado a su fuente; si tu categoría o grupo no es el indicado, dímelo.)';
    }

    /**
     * Render a short breakdown from `raw_values` (jsonb). Only scalar leaf values
     * are surfaced, joined "; ", capped — never a noisy structure dump. Returns ''
     * when there is nothing useful to add (the `value` already states the fact).
     *
     * @param  mixed  $raw
     */
    private function renderRawValues($raw): string
    {
        if (! is_array($raw) || $raw === []) {
            return '';
        }

        $parts = [];
        foreach ($raw as $key => $val) {
            if (is_scalar($val)) {
                $parts[] = is_int($key) ? (string) $val : "{$key}: {$val}";
            }
            if (count($parts) >= 6) {
                break;
            }
        }

        return mb_substr(implode('; ', $parts), 0, 240);
    }

    /**
     * Build the reference-fact citation: the source document, with `chunk_id =
     * null` and no page (structured data, not a prose chunk) — the salary citation
     * shape, marked `is_reference_fact`. Authority is `structured_reference` (the
     * fact's bounded level — it can never outrank a convenio). Returns [] when the
     * fact has no linked source document (still answered; nothing to persist).
     *
     * @return list<array<string,mixed>>
     */
    private function referenceFactCitation(ReferenceFact $fact): array
    {
        if (! $fact->source_document_id) {
            return [];
        }
        $doc = Document::with('convenio:id,name')->find($fact->source_document_id);
        if (! $doc) {
            return [];
        }

        $locator = $fact->source_locator ? " ({$fact->source_locator})" : '';

        return [[
            'chunk_id' => null, // a reference fact is structured data, never a vector chunk (ADR-0006)
            'document_id' => $doc->id,
            'document_uuid' => $doc->uuid,
            'document_title' => $doc->title,
            'authority_level' => ReferenceFact::AUTHORITY_LEVEL, // structured_reference (bounded)
            'page_from' => null,
            'page_to' => null,
            'page_number' => null,
            'snippet' => 'Dato de referencia'.($doc->convenio?->name ? ' — '.$doc->convenio->name : '').$locator,
            'is_reference_fact' => true,
        ]];
    }

    /**
     * @param  array<string,mixed>  $rf
     * @return array{outcome:string, answer:string, citations:list<array<string,mixed>>, escalation_reason:string, reference_fact:array<string,mixed>}
     */
    private function escalate(array $rf, string $note): array
    {
        $rf['outcome'] = 'escalate';
        $rf['note'] = $note;

        return [
            'outcome' => self::OUTCOME_ESCALATE,
            'answer' => self::COVERAGE_GAP_MESSAGE,
            'citations' => [],
            'escalation_reason' => self::ESCALATION_REASON,
            'reference_fact' => $rf,
        ];
    }
}
