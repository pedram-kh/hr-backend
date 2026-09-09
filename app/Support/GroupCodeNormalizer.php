<?php

namespace App\Support;

/**
 * Sprint 7f (ADR-0028) — turns a group label PRINTED IN A CONVENIO into the
 * stable `convenio_groups.code_normalized` key.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * WHAT THIS IS *NOT* FOR. It is never applied to
 * `convenio_job_categories.group_code`. That column is salary-spreadsheet
 * layout provenance: of its 94 rows, 72 hold nothing to normalize and 9 of the
 * remaining 22 hold a salary figure or a year. A rule that ingested those would
 * launder bad data into approved structure. Input here is convenio TEXT, read by
 * the Phase 2 proposer and approved by a human (ADR-0028).
 *
 * AND IT IS NEVER RUN AT ANSWER TIME. Phase 3's Tier 2 compares
 * `convenio_groups.id`, an integer. This runs once, at propose time, and its
 * output is shown to the reviewer next to the printed label so the key a node
 * will be compared by is visible before it is approved — not inferred later.
 * ────────────────────────────────────────────────────────────────────────────
 *
 * The rules, in the order they fire. `explain()` names which one did, which is
 * what the review surface displays and what the unit tests assert per rule.
 *
 *  1. de-accent + lowercase + collapse whitespace (shared `TextNormalizer` map)
 *  2. strip a leading group word (`grupo`/`grupos`/`grup`/`nivel`/`niveles`/
 *     `categoría profesional`) and trailing `.` `:` `)`
 *  3. ROMAN → ARABIC, but only when the whole remaining token is a numeral in
 *     I–X. Required, not cosmetic: fixture file 1 writes "Grupo 1" and file 2
 *     writes "Grupo I" for the same Valencia groups, and an exact matcher must
 *     see one node, not two.
 *  4. a DECIMAL IS PRESERVED VERBATIM AND NEVER SPLIT. `2.1` → `2.1`, never
 *     group 2 / sub-area 1. Cantabria's `2.1`–`4.1` are salary-table codes of
 *     unknown semantics, and reading a hierarchy out of a dot is the bare-digit
 *     regex error re-committed in new clothing. If a convenio really does use
 *     `2.1` hierarchically, the proposer says so with an excerpt and a human
 *     approves it.
 *  5. anything else becomes a hyphen slug. This is the rule that carries BOTH
 *     sub-areas (`área 5` → `area-5`, `resto áreas` → `resto-areas`) and the
 *     roughly one third of real gold-set group labels that are prose rather than
 *     numbers (`Obreros y subalternos`, `Técnicos titulados`). Those are
 *     legitimate groups that simply aren't numbered; they must be representable,
 *     they compare exactly like any other node, and nothing about them is
 *     guessed.
 *  6. AMBIGUITY NEVER RESOLVES SILENTLY. A label that reduces to nothing (`
 *     "Grupo"` on its own) returns `''`, and the caller must refuse it rather
 *     than invent a code.
 *
 * A compound label is deliberately NOT decomposed: `Grupos 1 y 2` normalizes to
 * `1-y-2`, not to two nodes. One label is one node. A fact that genuinely spans
 * two groups is expressed by BINDING it to two nodes
 * (`reference_fact_group_scopes`), which is why that table is many-to-many.
 */
class GroupCodeNormalizer
{
    /** Roman numerals I–X only; beyond X no real convenio numbers its groups. */
    private const ROMAN = [
        'i' => '1', 'ii' => '2', 'iii' => '3', 'iv' => '4', 'v' => '5',
        'vi' => '6', 'vii' => '7', 'viii' => '8', 'ix' => '9', 'x' => '10',
    ];

    /**
     * Longest first, so `grupos` is never left as a stray `s` and
     * `grupo profesional` is never left as a stray `profesional`.
     *
     * The `profesional`/`prof.` variants are not decoration. Hostelería
     * Navarra's own article 19 prints "Grupo Prof. 1.º" while the verified
     * reference fact for that same rule writes "Grupo 1"; its annex writes
     * "GRUPO PROFESIONAL TERCERO". Without these, one group would produce three
     * nodes (`prof-1`, `1`, `profesional-tercero`) and an exact matcher would
     * treat them as three different groups — the bug this class exists to
     * prevent, re-introduced by typography.
     */
    private const LEADING_WORDS = [
        'grupos profesionales', 'grupo profesional', 'categoria profesional',
        'grupos prof', 'grupo prof', 'niveles', 'nivel', 'grupos', 'grupo', 'grup',
    ];

    /**
     * Spanish ordinals as WORDS, for the same reason as the roman map: a
     * convenio that writes "GRUPO PROFESIONAL TERCERO" in its annex and
     * "Grupo Prof. 3.º" in its articles means one group, not two.
     */
    private const ORDINAL_WORDS = [
        'primero' => '1', 'primera' => '1', 'segundo' => '2', 'segunda' => '2',
        'tercero' => '3', 'tercera' => '3', 'cuarto' => '4', 'cuarta' => '4',
        'quinto' => '5', 'quinta' => '5', 'sexto' => '6', 'sexta' => '6',
        'septimo' => '7', 'septima' => '7', 'octavo' => '8', 'octava' => '8',
        'noveno' => '9', 'novena' => '9', 'decimo' => '10', 'decima' => '10',
    ];

    public const RULE_EMPTY = 'empty';

    public const RULE_ROMAN = 'roman_to_arabic';

    public const RULE_ORDINAL = 'ordinal_word_to_arabic';

    public const RULE_NUMERIC = 'numeric_verbatim';

    public const RULE_SLUG = 'slug';

    /** The comparison key, or `''` when the label carries no code at all. */
    public static function normalize(?string $label): string
    {
        return self::explain($label)['code'];
    }

    /**
     * The key plus the name of the rule that produced it, for the review surface
     * and for per-rule unit tests.
     *
     * @return array{code: string, rule: string, stripped: string}
     */
    public static function explain(?string $label): array
    {
        // Rule 1 — de-accent, lowercase, collapse whitespace.
        $s = TextNormalizer::deaccent($label);
        $s = trim(preg_replace('/\s+/u', ' ', $s) ?? $s);

        if ($s === '') {
            return ['code' => '', 'rule' => self::RULE_EMPTY, 'stripped' => ''];
        }

        // Rule 2 — drop a leading group word and trailing punctuation. Anchored
        // with a word boundary so `grup` cannot bite into `grupo`, and applied
        // once: "Grupo Grupo 1" is malformed input, not a double prefix.
        foreach (self::LEADING_WORDS as $word) {
            $stripped = preg_replace('/^'.preg_quote($word, '/').'\b\s*/u', '', $s, 1);
            if ($stripped !== $s) {
                $s = $stripped;
                break;
            }
        }
        // Trim punctuation at BOTH ends: stripping the group word off
        // "Grupo Prof. 1.º" leaves a leading ". ", which would otherwise slug
        // into the key.
        $s = trim(trim($s), " .:)-\u{00BA}\u{00AA}");

        // Rule 6 — the label was only the group word. Refuse; do not invent.
        if ($s === '') {
            return ['code' => '', 'rule' => self::RULE_EMPTY, 'stripped' => ''];
        }

        // Rule 3 — whole-token roman numeral, or a whole-token ordinal word.
        if (isset(self::ROMAN[$s])) {
            return ['code' => self::ROMAN[$s], 'rule' => self::RULE_ROMAN, 'stripped' => $s];
        }
        if (isset(self::ORDINAL_WORDS[$s])) {
            return ['code' => self::ORDINAL_WORDS[$s], 'rule' => self::RULE_ORDINAL, 'stripped' => $s];
        }

        // Rule 4 — an integer or a dotted code, kept exactly as written.
        if (preg_match('/^\d+(?:\.\d+)*$/', $s) === 1) {
            return ['code' => $s, 'rule' => self::RULE_NUMERIC, 'stripped' => $s];
        }

        // Rule 5 — slug. Dots are dropped here (they are only meaningful in the
        // numeric branch above), so `Grupo I. Jefes de Área` becomes
        // `i-jefes-de-area` rather than a code with stray punctuation.
        $slug = preg_replace('/[^a-z0-9]+/u', '-', $s) ?? $s;
        $slug = trim($slug, '-');

        return $slug === ''
            ? ['code' => '', 'rule' => self::RULE_EMPTY, 'stripped' => $s]
            : ['code' => $slug, 'rule' => self::RULE_SLUG, 'stripped' => $s];
    }
}
