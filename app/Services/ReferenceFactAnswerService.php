<?php

namespace App\Services;

use App\Models\ConvenioGroup;
use App\Models\Document;
use App\Models\Employee;
use App\Models\ReferenceFact;
use App\Models\ReferenceFactGroupScope;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

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
 *   2. a fact bound to the employee's APPROVED group node — an integer id
 *      comparison, no text parsed at answer time (Sprint 7f, ADR-0028); an
 *      indeterminate scope escalates here rather than falling to 3, else
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

        // Tier 2 — the employee's APPROVED group node, compared by id (7f/ADR-0028).
        // Skipped entirely when the employee has no node, exactly as before: on
        // day one every real profile has `convenio_group_id = null`, so this tier
        // is inert until an admin assigns one.
        if ($employee->convenio_group_id !== null) {
            $group = $this->matchByGroupNode($candidates, $employee->convenio_group_id);

            // A hard stop, not a skip. Once a bound node proves the employee's
            // scope indeterminate, falling through to Tier 3 would answer
            // convenio-wide — less specific than the evidence we can see, and
            // stated with the same confidence. This is the one place the ladder's
            // "else continue" shape changes, and it changes toward escalation.
            if ($group['escalate'] !== null) {
                $rf['match_kind'] = 'group';
                $rf['group_node_id'] = $employee->convenio_group_id;

                return $this->escalate($rf, $group['escalate']);
            }

            if ($group['facts']->isNotEmpty()) {
                return $this->resolveAndAnswer($group['facts'], $rf, 'group', $group['node']);
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
     * fact. `$matchKind` ∈ job_category | group | convenio_wide.
     *
     * @param  Collection<int, ReferenceFact>  $tier
     * @param  array<string,mixed>  $rf
     * @return array{outcome:string, answer:string, citations:list<array<string,mixed>>, escalation_reason:?string, reference_fact:array<string,mixed>}
     */
    private function resolveAndAnswer($tier, array $rf, string $matchKind, ?ConvenioGroup $node): array
    {
        [$fact, $selection] = $this->selectMostRecent($tier);

        if ($fact === null) {
            $rf['validity_selection'] = $selection; // 'ambiguous_conflict'
            $rf['match_kind'] = $matchKind;
            if ($node !== null) {
                $rf['group_node_id'] = $node->id;
            }

            return $this->escalate($rf, 'two verified facts with the same most-recent validity and differing values — escalate, do not blend (resolution is 7d)');
        }

        $rf['fact_id'] = $fact->id;
        $rf['value'] = $fact->value;
        $rf['validity_selection'] = $selection; // 'single' | 'most_recent_validity'
        $rf['match_kind'] = $matchKind;
        // `group_label` keeps carrying the FACT's printed string, for display and
        // citation continuity; the node is what the match was actually made on,
        // and it is recorded separately rather than folded into the label. These
        // two can legitimately disagree — "Grupo 2 excepto área cinco" bound to
        // "resto áreas" — and the trace should show both.
        $rf['group_label'] = $fact->group_label;
        if ($node !== null) {
            $rf['group_node_id'] = $node->id;
            $rf['group_node_label'] = $node->label;
        }
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
     * @param  Collection<int, ReferenceFact>  $facts
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

    /**
     * Tier 2's comparison: the employee's approved group node against the nodes
     * each fact is bound to. Integer equality — no strings are parsed at answer
     * time, which is the entire point of Sprint 7f.
     *
     * What this replaces: `factMatchesGroup()` searched the fact's free-text
     * `group_label` for the employee's `job_category.group_code` as a bare digit.
     * Two independent failures, both live: nine of the corpus's 22 `group_code`
     * values are not group codes at all (§1.2), and "Grupo 2 excepto área cinco"
     * contains the digit 2 — so an employee in *área 5* matched the fact for
     * everyone EXCEPT área 5, and got 60 días where the convenio says 90. A
     * confident, cited, wrong answer, which is the worst kind.
     *
     * The rules, per fact, against the employee's node E:
     *
     *   (1) a bound node IS E                       → match
     *   (2) a bound node is a CHILD of E            → escalate: the fact is
     *       sub-area-specific and we only know the employee's group, so we
     *       cannot tell which slice they are in
     *   (3) a bound node is E's PARENT and that
     *       parent has approved children            → escalate: the fact claims
     *       a group the convenio has since split, so the FACT's scope is the
     *       ambiguous one
     *   (4) otherwise                               → no match, fall through
     *
     * Rule (1) is checked across all of a fact's nodes before (2)/(3), so a
     * compound fact bound to {G1, G2›área 5} answers for an employee at G1 on
     * the strength of its G1 binding. A fact with ZERO bound nodes can never
     * match here — it falls to Tier 3, which requires a null `group_label`, so
     * a group-labelled but unbound fact reaches Tier 4 and escalates. That is
     * deliberate: an unbound label is a fact nobody has vouched for.
     *
     * @param  Collection<int, ReferenceFact>  $candidates
     * @return array{facts: Collection<int, ReferenceFact>, escalate: ?string, node: ?ConvenioGroup}
     */
    private function matchByGroupNode($candidates, int $employeeNodeId): array
    {
        $none = ['facts' => collect(), 'escalate' => null, 'node' => null];

        $employeeNode = ConvenioGroup::find($employeeNodeId);
        if ($employeeNode === null || $employeeNode->status !== ConvenioGroup::STATUS_APPROVED) {
            // A node that was rejected out from under an assigned employee. Not
            // a match and not an escalation: the ladder continues as if no group
            // were set, which is the same safe place a null node lands in.
            return $none;
        }

        $boundByFact = ReferenceFactGroupScope::query()
            ->whereIn('reference_fact_id', $candidates->pluck('id'))
            ->get()
            ->groupBy('reference_fact_id');

        if ($boundByFact->isEmpty()) {
            return $none;
        }

        $nodes = ConvenioGroup::whereIn('id', $boundByFact->flatten()->pluck('convenio_group_id')->unique())
            ->get()
            ->keyBy('id');

        $matched = collect();
        $indeterminate = [];

        foreach ($candidates as $fact) {
            $factNodes = ($boundByFact[$fact->id] ?? collect())
                ->map(fn (ReferenceFactGroupScope $s) => $nodes->get($s->convenio_group_id))
                ->filter();

            if ($factNodes->contains(fn (ConvenioGroup $n) => $n->id === $employeeNode->id)) {
                $matched->push($fact);

                continue;
            }

            foreach ($factNodes as $n) {
                if ($n->parent_id === $employeeNode->id) {
                    $indeterminate[] = "fact {$fact->id} is scoped to sub-area \"{$n->label}\" of the employee's "
                        ."group \"{$employeeNode->label}\" — the employee's sub-area is unknown";
                    break;
                }

                if ($n->id === $employeeNode->parent_id && $this->hasApprovedChildren($n)) {
                    $indeterminate[] = "fact {$fact->id} is scoped to group \"{$n->label}\", which the convenio "
                        .'splits into sub-areas that carry different values — the fact\'s own scope is ambiguous';
                    break;
                }
            }
        }

        if ($indeterminate !== []) {
            return [
                'facts' => collect(),
                'escalate' => 'group scope is indeterminate, escalate rather than answer less specifically than '
                    .'the evidence: '.implode('; ', $indeterminate),
                'node' => $employeeNode,
            ];
        }

        return ['facts' => $matched->values(), 'escalate' => null, 'node' => $employeeNode];
    }

    private function hasApprovedChildren(ConvenioGroup $node): bool
    {
        return ConvenioGroup::where('parent_id', $node->id)
            ->where('status', ConvenioGroup::STATUS_APPROVED)
            ->exists();
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
     * The cap drops WHOLE entries, never cutting mid-string (Correction-salary-01
     * follow-up): a character-wise `mb_substr` could end a breakdown halfway
     * through a figure — "1.234,56" printed as "1.23" — which is a number the
     * source does not contain, arrived at by truncation instead of by division.
     * Same rule as everywhere else: quote a source figure whole, or omit it.
     *
     * @param  mixed  $raw
     */
    private function renderRawValues($raw): string
    {
        if (! is_array($raw) || $raw === []) {
            return '';
        }

        $parts = [];
        $length = 0;
        foreach ($raw as $key => $val) {
            if (! is_scalar($val)) {
                continue;
            }
            $part = is_int($key) ? (string) $val : "{$key}: {$val}";
            $separator = $parts === [] ? 0 : 2;
            if ($length + $separator + mb_strlen($part) > 240) {
                break;
            }
            $parts[] = $part;
            $length += $separator + mb_strlen($part);
            if (count($parts) >= 6) {
                break;
            }
        }

        return implode('; ', $parts);
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
