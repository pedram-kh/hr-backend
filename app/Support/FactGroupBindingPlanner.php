<?php

namespace App\Support;

use App\Models\ConvenioGroup;
use App\Models\ReferenceFact;
use Illuminate\Support\Collection;

/**
 * Sprint 7f (ADR-0028) — reads a reference fact's free-text `group_label` and
 * works out which APPROVED `convenio_groups` nodes it refers to.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * WHY THIS IS A PLANNER AND NOT A MATCHER. Nothing this class returns is
 * written by this class. It produces the BINDING DIFF a reviewer confirms
 * before any `reference_fact_group_scopes` row exists (§4). Once a fact is
 * bound, Phase 3's Tier 2 compares `convenio_groups.id` — an integer — and this
 * code is not consulted again. So this runs once per fact, in front of a human,
 * and it is allowed to say "I don't know".
 *
 * THAT LAST PART IS THE DESIGN. The bug this sprint fixes is a matcher that
 * always produced an answer: `preg_match('/\d/')` on a label, which reads
 * "Grupo 2 (área 5)" as group 2 and silently mis-scopes it. Replacing one
 * over-confident parser with a cleverer over-confident parser would fix the
 * symptom and keep the disease. So every label this class cannot PROVE is
 * returned as unresolved, with the reason and the segment that failed, for the
 * reviewer to bind by hand. An unresolved label costs a click; a wrongly
 * resolved one is a wrong answer about someone's probation period.
 * ────────────────────────────────────────────────────────────────────────────
 *
 * The patterns it does prove, all seen in the real corpus:
 *
 *   "Grupo 1"                                   → [G1]            exact
 *   "Grupo I"                                   → [G1]            exact (roman)
 *   "Grupos 3, 4, 5 y 6"                        → [G3,G4,G5,G6]   enumeration
 *   "Grupos 1 y 2"                              → [G1,G2]         enumeration
 *   "Grupo 3 (todas las áreas)"                 → [G3]            whole group
 *   "Grupo 2 (resto áreas)"                     → [G2›resto áreas] sub-area
 *   "Grupo 1 (todas las áreas) y Grupo 2 (área 5)" → [G1, G2›área 5]  compound
 *   "Grupo 1 y área cinco de Grupo 2"           → [G1, G2›área 5]  compound, inverted
 *
 * And the ones it deliberately refuses:
 *
 *   "Resto de grupos"                → complement — depends on which groups the
 *                                      OTHER facts claim, which is a semantic
 *                                      judgement, not a parse.
 *   "Grupo 2 excepto área cinco"     → complement within a group. It happens to
 *                                      mean "resto áreas" here, but only because
 *                                      a human read the convenio and split G2
 *                                      that way; deriving it would be inference.
 *   "Grupo 2", where G2 IS SPLIT     → ambiguous. Whole group, or the reviewer
 *                                      forgot the area? Under-specified labels
 *                                      are how the digit matcher goes wrong.
 *   "Contratos de formación en alternancia" → not a group at all (a real label on
 *                                      fact 82 — a contract type parked in the
 *                                      group column).
 *   "Establecido en COEAS ESTATAL"   → a cross-reference, not a group (fact 29).
 *
 * A PARTIAL parse is never a partial binding: if any segment of a compound
 * label fails, the whole label is unresolved. Binding a fact to *some* of the
 * groups it names is worse than binding it to none — it would answer confidently
 * for the groups it caught and silently omit the rest.
 */
class FactGroupBindingPlanner
{
    /** Resolved to specific nodes; safe to offer as a pre-ticked binding. */
    public const STATUS_RESOLVED = 'resolved';

    /** A real group reference this class refuses to resolve — human binds it. */
    public const STATUS_NEEDS_HUMAN = 'needs_human';

    /** Not a group reference at all — expected to stay unbound. */
    public const STATUS_NOT_A_GROUP = 'not_a_group';

    /** The label is null/empty: a convenio-wide fact, which binds to nothing. */
    public const STATUS_CONVENIO_WIDE = 'convenio_wide';

    /**
     * Spanish word numerals, needed only inside an area qualifier: fact 34
     * writes "área cinco de Grupo 2" where fact 44 writes "Grupo 2 (área 5)".
     * Both name the same slice, so both must reach the same node.
     */
    private const WORD_NUMERALS = [
        'uno' => '1', 'una' => '1', 'primera' => '1', 'primero' => '1',
        'dos' => '2', 'segunda' => '2', 'segundo' => '2',
        'tres' => '3', 'tercera' => '3', 'tercero' => '3',
        'cuatro' => '4', 'cuarta' => '4', 'cuarto' => '4',
        'cinco' => '5', 'quinta' => '5', 'quinto' => '5',
        'seis' => '6', 'sexta' => '6', 'sexto' => '6',
        'siete' => '7', 'ocho' => '8', 'nueve' => '9', 'diez' => '10',
    ];

    /** Qualifiers meaning "the whole group", i.e. the parent node itself. */
    private const WHOLE_GROUP_QUALIFIERS = [
        'todas las areas', 'todas las area', 'todas areas', 'todas sus areas',
        'todas', 'todo', 'general', 'todas las categorias',
    ];

    /**
     * Markers of a COMPLEMENT ("the rest", "except X"). These are the labels that
     * look parseable and are not: they are defined by what they exclude, so
     * resolving them means knowing the full set, which is the reviewer's call.
     */
    private const COMPLEMENT_MARKERS = [
        'resto de grupos', 'resto de los grupos', 'demas grupos', 'los demas grupos',
        'resto grupos', 'excepto', 'salvo', 'exceptuando', 'menos el', 'a excepcion',
    ];

    /**
     * Plan the bindings for every fact of one convenio against its APPROVED
     * group tree.
     *
     * @param  Collection<int,ReferenceFact>  $facts
     * @param  Collection<int,ConvenioGroup>  $nodes  approved nodes of this convenio
     * @return list<array<string,mixed>>
     */
    public function planMany(Collection $facts, Collection $nodes): array
    {
        return $facts->map(fn (ReferenceFact $fact) => $this->plan($fact, $nodes))->values()->all();
    }

    /**
     * @param  Collection<int,ConvenioGroup>  $nodes
     * @return array<string,mixed>
     */
    public function plan(ReferenceFact $fact, Collection $nodes): array
    {
        $label = (string) ($fact->group_label ?? '');
        $result = $this->resolveLabel($label, $nodes);

        return [
            'fact_id' => $fact->id,
            'fact_uuid' => $fact->uuid,
            'fact_status' => $fact->status,
            'group_label' => $fact->group_label,
            'value' => $fact->value,
            'validity_start' => $fact->validity_start?->toDateString(),
            'validity_end' => $fact->validity_end?->toDateString(),
            ...$result,
        ];
    }

    /**
     * The parse itself. Split out from `plan()` so the label grammar can be unit
     * tested without a database row.
     *
     * @param  Collection<int,ConvenioGroup>  $nodes
     * @return array{status:string, node_ids:list<int>, kind:string, reason:?string, unresolved_segment:?string}
     */
    public function resolveLabel(?string $label, Collection $nodes): array
    {
        $raw = trim((string) $label);
        if ($raw === '') {
            return $this->outcome(self::STATUS_CONVENIO_WIDE, [], 'convenio_wide',
                'Sin etiqueta de grupo: el dato es de ámbito convenio (Tier 3) y no se vincula a ningún nodo.');
        }

        $flat = TextNormalizer::deaccent($raw);
        $flat = trim(preg_replace('/\s+/u', ' ', $flat) ?? $flat);

        foreach (self::COMPLEMENT_MARKERS as $marker) {
            if (str_contains($flat, $marker)) {
                return $this->outcome(self::STATUS_NEEDS_HUMAN, [], 'complement',
                    'La etiqueta se define por exclusión ("'.$raw.'"): depende de qué grupos '
                    .'reclaman los demás datos, que es un juicio humano, no una lectura. Vincúlala a mano.');
            }
        }

        // Group the approved nodes for lookup. Roots by code; children by
        // (parent code, own code).
        $roots = [];
        $children = [];
        foreach ($nodes as $node) {
            if ($node->parent_id === null) {
                $roots[$node->code_normalized] = $node;
            }
        }
        foreach ($nodes as $node) {
            if ($node->parent_id !== null) {
                $children[$node->parent_id][$node->code_normalized] = $node;
            }
        }

        if ($roots === []) {
            return $this->outcome(self::STATUS_NEEDS_HUMAN, [], 'no_structure',
                'Este convenio todavía no tiene ninguna estructura de grupos aprobada.');
        }

        $segments = $this->splitSegments($flat);
        if ($segments === []) {
            return $this->outcome(self::STATUS_NOT_A_GROUP, [], 'unrecognized',
                'No se reconoce ninguna referencia a un grupo en "'.$raw.'".');
        }

        $nodeIds = [];

        foreach ($segments as $segment) {
            $parsed = $this->parseSegment($segment);
            if ($parsed === null) {
                return $this->outcome(self::STATUS_NOT_A_GROUP, [], 'unrecognized',
                    'El fragmento "'.$segment.'" de "'.$raw.'" no nombra un grupo.', $segment);
            }

            [$rootCode, $qualifier] = $parsed;

            $root = $roots[$rootCode] ?? null;
            if ($root === null) {
                return $this->outcome(self::STATUS_NEEDS_HUMAN, [], 'unknown_group',
                    'El fragmento "'.$segment.'" de "'.$raw.'" apunta a un grupo ('.$rootCode
                    .') que no existe en la estructura aprobada de este convenio.', $segment);
            }

            $rootChildren = $children[$root->id] ?? [];

            if ($qualifier === null) {
                // A bare group reference. If the group is SPLIT, this is
                // under-specified — exactly the shape the digit matcher gets
                // wrong — so it goes to a human rather than defaulting to
                // "the whole group".
                if ($rootChildren !== []) {
                    return $this->outcome(self::STATUS_NEEDS_HUMAN, [], 'ambiguous_split_group',
                        '"'.$segment.'" nombra un grupo que está dividido en áreas con valores '
                        .'distintos, sin decir cuál. Elige a mano si el dato cubre todo el grupo '
                        .'o solo un área.', $segment);
                }
                $nodeIds[] = $root->id;

                continue;
            }

            if ($this->isWholeGroupQualifier($qualifier)) {
                $nodeIds[] = $root->id;

                continue;
            }

            $childCode = GroupCodeNormalizer::normalize($this->expandWordNumerals($qualifier));
            $child = $rootChildren[$childCode] ?? null;
            if ($child === null) {
                return $this->outcome(self::STATUS_NEEDS_HUMAN, [], 'unknown_sub_area',
                    'El fragmento "'.$segment.'" apunta a un área ('.($childCode ?: '¿?').') que no '
                    .'existe bajo ese grupo en la estructura aprobada.', $segment);
            }
            $nodeIds[] = $child->id;
        }

        $nodeIds = array_values(array_unique($nodeIds));
        if ($nodeIds === []) {
            return $this->outcome(self::STATUS_NOT_A_GROUP, [], 'unrecognized',
                'No se reconoce ninguna referencia a un grupo en "'.$raw.'".');
        }

        return $this->outcome(
            self::STATUS_RESOLVED,
            $nodeIds,
            count($nodeIds) > 1 ? 'compound' : 'exact',
            null,
        );
    }

    /**
     * Split a label into the group references it names, keeping parenthesised
     * qualifiers attached to their group. Splits on `y`, `e`, `,` and `;` only
     * OUTSIDE parentheses, so "Grupo 1 (todas las áreas) y Grupo 2 (área 5)"
     * yields two segments and not four.
     *
     * @return list<string>
     */
    private function splitSegments(string $flat): array
    {
        $depth = 0;
        $buffer = '';
        $segments = [];
        $length = mb_strlen($flat);

        for ($i = 0; $i < $length; $i++) {
            $char = mb_substr($flat, $i, 1);

            if ($char === '(') {
                $depth++;
                $buffer .= $char;

                continue;
            }
            if ($char === ')') {
                $depth = max(0, $depth - 1);
                $buffer .= $char;

                continue;
            }

            if ($depth === 0) {
                if ($char === ',' || $char === ';') {
                    $segments[] = $buffer;
                    $buffer = '';

                    continue;
                }
                // " y " / " e " as a separator, never the `y` inside a word.
                if ($char === ' ' && preg_match('/^ (?:y|e) /u', mb_substr($flat, $i, 3)) === 1) {
                    $segments[] = $buffer;
                    $buffer = '';
                    $i += 2;

                    continue;
                }
            }

            $buffer .= $char;
        }
        $segments[] = $buffer;

        return array_values(array_filter(array_map('trim', $segments), fn ($s) => $s !== ''));
    }

    /**
     * Parse one segment into [root code, qualifier|null].
     *
     * @return array{0:string,1:?string}|null
     */
    private function parseSegment(string $segment): ?array
    {
        $s = trim($segment);

        // Inverted syntax: "área cinco de Grupo 2" — the qualifier leads.
        if (preg_match('/^(.+?)\s+de\s+(grupos?|niveles?)\s*(.+)$/u', $s, $m) === 1) {
            $rootCode = GroupCodeNormalizer::normalize($m[3]);

            return $rootCode === '' ? null : [$rootCode, trim($m[1])];
        }

        // Parenthesised qualifier: "grupo 2 (area 5)".
        $qualifier = null;
        if (preg_match('/^(.*?)\s*\(([^)]*)\)\s*$/u', $s, $m) === 1) {
            $s = trim($m[1]);
            $qualifier = trim($m[2]) !== '' ? trim($m[2]) : null;
        }

        // A bare numeral: the tail of an enumeration ("Grupos 3, 4, 5 y 6"
        // splits into "grupos 3", "4", "5", "6" — the last three carry no group
        // word of their own, and the numeral IS the code).
        if (preg_match('/^\d+(?:\.\d+)*$/', $s) === 1) {
            return [$s, $qualifier];
        }

        // A segment must actually say "grupo"/"nivel" to be a group reference.
        // Without this, "Contratos de formación en alternancia" would slug into
        // a plausible-looking code and match nothing — or worse, something.
        if (preg_match('/^(?:grupos?|grup|niveles?|categoria profesional)\b/u', $s) !== 1) {
            return null;
        }

        $rootCode = GroupCodeNormalizer::normalize($s);

        return $rootCode === '' ? null : [$rootCode, $qualifier];
    }

    private function isWholeGroupQualifier(string $qualifier): bool
    {
        $q = trim(TextNormalizer::deaccent($qualifier));

        return in_array($q, self::WHOLE_GROUP_QUALIFIERS, true);
    }

    /** "area cinco" → "area 5", so both spellings reach one node. */
    private function expandWordNumerals(string $value): string
    {
        return preg_replace_callback(
            '/\b('.implode('|', array_keys(self::WORD_NUMERALS)).')\b/u',
            fn (array $m) => self::WORD_NUMERALS[$m[1]],
            TextNormalizer::deaccent($value),
        ) ?? $value;
    }

    /**
     * @param  list<int>  $nodeIds
     * @return array{status:string, node_ids:list<int>, kind:string, reason:?string, unresolved_segment:?string}
     */
    private function outcome(
        string $status,
        array $nodeIds,
        string $kind,
        ?string $reason,
        ?string $segment = null,
    ): array {
        return [
            'status' => $status,
            'node_ids' => $nodeIds,
            'kind' => $kind,
            'reason' => $reason,
            'unresolved_segment' => $segment,
        ];
    }
}
