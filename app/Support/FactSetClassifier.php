<?php

namespace App\Support;

use App\Models\ReferenceFact;

/**
 * Slice 13d (ADR-0037) — may several verified facts that tie on one topic be
 * ANSWERED TOGETHER, or must the tie escalate?
 *
 * Pure and deterministic: no database, no model, nothing written. Shared by the
 * answer service and by `facts:same-topic-audit` so the audit reports exactly
 * what the route will do and the two cannot drift.
 *
 * THE RULE (R-Q — "same quantity", not "same logical key"):
 *
 *   Two facts are COMPLEMENTARY iff the named quantities in their `raw_values`
 *   are DISJOINT. Everything the rule cannot PROVE complementary is
 *   CONTRADICTORY, i.e. today's behaviour (escalate, do not blend):
 *
 *     - `raw_values` null / empty / a JSON list  → no named quantity to vouch for
 *     - any shared key (after normalisation)     → same quantity, differing value
 *     - a `duplicate_of_id` link between the two whose `resolution` is still
 *       null (7b-2 flagged "probable versions", no human decided)  → contradictory
 *       even when the keys look disjoint. `coexists` does NOT unlock composition.
 *
 *   A cohort is a COMPLEMENTARY SET only if EVERY pair is complementary. One
 *   contradictory pair escalates the WHOLE cohort — never a subset, never "the
 *   compatible ones".
 *
 * WHY THE LOGICAL KEY CANNOT DISCRIMINATE (ADR-0037): facts 140/143 on staging
 * carry an identical full key, and within the convenio-wide tier every candidate
 * has the same key by construction. In the job-category / group tiers the only
 * component that can differ is `group_label`, a printed string (ADR-0028), and
 * "Grupo 2" / "Grupo 2 (área 5)" bound to one node are exactly the probable
 * versions 7d exists to resolve.
 *
 * RESIDUAL RISK (accepted, ADR-0037): a pair about the SAME quantity under
 * DIFFERENT key names classifies as complementary and is composed — two figures,
 * each verbatim and each cited (visible, never silent). The audit lists every
 * complementary pair with both key sets for a human look after each ingestion
 * and triage batch.
 */
final class FactSetClassifier
{
    public const COMPLEMENTARY = 'complementary';

    public const CONTRADICTORY = 'contradictory';

    /** Set-level composition verdicts (the trace's `fact_set.composition`). */
    public const SET_COMPLEMENTARY = 'complementary';

    public const SET_CONFLICT = 'conflict';

    /** At most this many facts are answered together; the rest ride in the trace. */
    public const MAX_SET = 3;

    /** Ordering rule string recorded in the trace. */
    public const ORDER_RULE = 'figures_desc,length_asc,id_asc';

    /**
     * Quantity keys of a fact, or null when it cannot vouch for any (⇒ fail toward
     * escalation).
     *
     * @return list<string>|null
     */
    public static function quantityKeys(ReferenceFact $fact): ?array
    {
        $raw = $fact->raw_values;
        if (! is_array($raw) || $raw === [] || array_is_list($raw)) {
            return null; // null / [] / JSON list: no named quantities
        }

        $keys = [];
        foreach (array_keys($raw) as $k) {
            $norm = self::normaliseKey((string) $k);
            if ($norm !== '') {
                $keys[$norm] = true;
            }
        }

        // PHP turns numeric-string array keys ("2025") into ints — keep them strings.
        return $keys === [] ? null : array_map('strval', array_keys($keys));
    }

    /** Lowercase, accent-stripped, every non-alphanumeric run → "_". */
    public static function normaliseKey(string $key): string
    {
        $k = mb_strtolower(trim($key));
        $k = strtr($k, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
        $k = (string) preg_replace('/[^a-z0-9]+/', '_', $k);

        return trim($k, '_');
    }

    /**
     * Relation of one pair.
     *
     * @return array{a:int, b:int, relation:string, reason:string, shared_keys:list<string>}
     */
    public static function relate(ReferenceFact $a, ReferenceFact $b): array
    {
        $base = ['a' => (int) $a->id, 'b' => (int) $b->id];

        if (self::flaggedAsVersionsUnresolved($a, $b)) {
            return $base + ['relation' => self::CONTRADICTORY, 'reason' => 'flagged_duplicate_unresolved', 'shared_keys' => []];
        }

        $ka = self::quantityKeys($a);
        $kb = self::quantityKeys($b);
        if ($ka === null || $kb === null) {
            return $base + ['relation' => self::CONTRADICTORY, 'reason' => 'no_quantity_keys', 'shared_keys' => []];
        }

        $shared = array_values(array_intersect($ka, $kb));

        return $shared === []
            ? $base + ['relation' => self::COMPLEMENTARY, 'reason' => 'disjoint_quantity_keys', 'shared_keys' => []]
            : $base + ['relation' => self::CONTRADICTORY, 'reason' => 'same_quantity', 'shared_keys' => $shared];
    }

    /** 7b-2 flagged the pair as probable versions and no human resolved it. */
    private static function flaggedAsVersionsUnresolved(ReferenceFact $a, ReferenceFact $b): bool
    {
        $link = ($a->duplicate_of_id !== null && (int) $a->duplicate_of_id === (int) $b->id && $a->resolution === null)
            || ($b->duplicate_of_id !== null && (int) $b->duplicate_of_id === (int) $a->id && $b->resolution === null);

        return $link;
    }

    /**
     * Collapse a cohort to one fact per distinct `value` (the lowest id), ordered
     * by id. Byte-identical values are duplicates, not competing statements.
     *
     * @param  iterable<ReferenceFact>  $facts
     * @return list<ReferenceFact>
     */
    public static function distinctByValue(iterable $facts): array
    {
        $byId = [];
        foreach ($facts as $f) {
            $byId[(int) $f->id] = $f;
        }
        ksort($byId);

        $seen = [];
        $out = [];
        foreach ($byId as $f) {
            if (isset($seen[$f->value])) {
                continue;
            }
            $seen[$f->value] = true;
            $out[] = $f;
        }

        return $out;
    }

    /**
     * Classify a tie cohort (facts sharing the top `validity_start`, already
     * reduced to distinct values). All-or-nothing.
     *
     * @param  list<ReferenceFact>  $cohort
     * @return array{composition:string, cohort_ids:list<int>, pairs:list<array<string,mixed>>}
     */
    public static function classifySet(array $cohort): array
    {
        $pairs = [];
        $n = count($cohort);
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $pairs[] = self::relate($cohort[$i], $cohort[$j]);
            }
        }

        $contradictory = array_filter($pairs, fn (array $p) => $p['relation'] === self::CONTRADICTORY);

        return [
            'composition' => $n >= 2 && $contradictory === [] ? self::SET_COMPLEMENTARY : self::SET_CONFLICT,
            'cohort_ids' => array_map(fn (ReferenceFact $f) => (int) $f->id, $cohort),
            'pairs' => $pairs,
        ];
    }

    /**
     * Deterministic order of a complementary set, independent of DB row order:
     * figure-bearing first (more distinct (unit, figure) pairs in `value`, desc),
     * then shorter `value`, then lower id.
     *
     * @param  list<ReferenceFact>  $facts
     * @return list<ReferenceFact>
     */
    public static function order(array $facts): array
    {
        $keyed = array_map(fn (ReferenceFact $f) => [
            'fact' => $f,
            'figures' => self::figureCount((string) $f->value),
            'length' => mb_strlen((string) $f->value),
            'id' => (int) $f->id,
        ], $facts);

        usort($keyed, fn (array $x, array $y) => [$y['figures'], $x['length'], $x['id']] <=> [$x['figures'], $y['length'], $y['id']]);

        return array_map(fn (array $k) => $k['fact'], $keyed);
    }

    /**
     * Number of distinct (unit, figure) pairs in a text. Mirrors
     * `ReferenceFactPath::extractFiguresByUnit()` (same regex, same digit
     * normalisation, same unit canonicalisation) — a test pins the equivalence so
     * the two cannot drift.
     */
    public static function figureCount(string $text): int
    {
        $unitPattern = 'd[ií]as?|meses|mes|horas?|años?|anos?|semanas?|€|euros?';
        preg_match_all('/(\d[\d.,]*)\s*('.$unitPattern.')/iu', $text, $matches, PREG_SET_ORDER);

        $pairs = [];
        foreach ($matches as $m) {
            $value = (string) preg_replace('/(?<=\d)\.(?=\d{3}\b)/', '', $m[1]);
            $unit = self::canonicalUnit($m[2]);
            if ($value === '' || $unit === '') {
                continue;
            }
            $pairs[$unit."\0".$value] = true;
        }

        return count($pairs);
    }

    private static function canonicalUnit(string $raw): string
    {
        $u = strtr(mb_strtolower(trim($raw)), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u']);

        return match (true) {
            str_starts_with($u, 'dia') => 'dia',
            $u === 'mes' || $u === 'meses' => 'mes',
            str_starts_with($u, 'hora') => 'hora',
            str_starts_with($u, 'ano') => 'ano',
            str_starts_with($u, 'semana') => 'semana',
            $u === '€' || str_starts_with($u, 'euro') => 'euro',
            default => '',
        };
    }
}
