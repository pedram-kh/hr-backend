<?php

namespace Tests\Unit;

use App\Support\GroupCodeNormalizer as N;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Sprint 7f (ADR-0028) — one test per normalization rule, plus the cases the
 * plan singles out as load-bearing. Every label below is a REAL string from the
 * corpus, the 7b-2 gold set, or staging's verified facts — not an invented one.
 */
class GroupCodeNormalizerTest extends TestCase
{
    /** Rule 1 — de-accent, lowercase, collapse whitespace. */
    public function test_rule_1_deaccents_lowercases_and_collapses_whitespace(): void
    {
        $this->assertSame('area-5', N::normalize('ÁREA   5'));
        $this->assertSame('resto-areas', N::normalize("  Resto \n Áreas "));
    }

    /** Rule 2 — a leading group word and trailing punctuation are dropped. */
    #[DataProvider('leadingWordCases')]
    public function test_rule_2_strips_leading_group_word_and_trailing_punctuation(string $label, string $expected): void
    {
        $this->assertSame($expected, N::normalize($label));
    }

    public static function leadingWordCases(): array
    {
        return [
            'grupo' => ['Grupo 2', '2'],
            'grupos (plural)' => ['Grupos 3', '3'],
            'grup (abbreviated)' => ['Grup 4', '4'],
            'nivel' => ['Nivel 2', '2'],
            'niveles' => ['Niveles 2', '2'],
            'categoria profesional' => ['Categoría profesional 3', '3'],
            'trailing colon' => ['Grupo 1:', '1'],
            'trailing period' => ['Grupo 1.', '1'],
            'trailing paren' => ['Grupo 1)', '1'],
        ];
    }

    /**
     * `grup` must not bite into `grupo` — with a naive prefix strip, "Grupo 2"
     * would lose only "grup" and normalize to "o-2".
     */
    public function test_rule_2_word_boundary_protects_longer_words(): void
    {
        $this->assertSame('2', N::normalize('Grupo 2'));
        $this->assertSame('2', N::normalize('Grupos 2'));
        $this->assertNotSame('o-2', N::normalize('Grupo 2'));
    }

    /** A leading word that is the WHOLE label carries no code. Rule 6: refuse. */
    public function test_rule_2_and_6_a_bare_group_word_yields_no_code(): void
    {
        $this->assertSame('', N::normalize('Grupo'));
        $this->assertSame('', N::normalize('Grupo:'));
        $this->assertSame(N::RULE_EMPTY, N::explain('Grupo')['rule']);
    }

    /**
     * Rule 3 — roman → arabic. This is the rule that makes the two fixtures agree:
     * file 1 prints "Grupo 1" and file 2 prints "Grupo I" for the same Valencia
     * groups, and an exact matcher must see ONE node, not two.
     */
    public function test_rule_3_roman_numerals_collapse_onto_their_arabic_node(): void
    {
        $this->assertSame(N::normalize('Grupo 1'), N::normalize('Grupo I'));
        $this->assertSame('1', N::normalize('Grupo I'));
        $this->assertSame('4', N::normalize('Grupo IV'));
        $this->assertSame('6', N::normalize('Grupo VI'));
        $this->assertSame('10', N::normalize('Grupo X'));
        $this->assertSame(N::RULE_ROMAN, N::explain('Grupo IV')['rule']);
    }

    /** Only a WHOLE roman token converts — a word that merely starts with one does not. */
    public function test_rule_3_only_converts_a_whole_token(): void
    {
        // Real gold-set labels. "Ix..." must not become "9...".
        $this->assertSame('vigilantes', N::normalize('Vigilantes'));
        $this->assertSame('i-jefes-de-area', N::normalize('Grupo I. Jefes de Área'));
    }

    /**
     * Rule 4 — THE CONSERVATIVE READING. A decimal is preserved verbatim and
     * never split into group + sub-area. Cantabria's `2.1`–`4.1` are salary-table
     * codes of unknown semantics; reading a hierarchy out of a dot is the
     * bare-digit regex error re-committed.
     */
    #[DataProvider('decimalCases')]
    public function test_rule_4_decimals_are_preserved_never_split(string $label, string $expected): void
    {
        $this->assertSame($expected, N::normalize($label));
        $this->assertSame(N::RULE_NUMERIC, N::explain($label)['rule']);
    }

    public static function decimalCases(): array
    {
        return [
            ['2.1', '2.1'],
            ['3.1', '3.1'],
            ['4.1', '4.1'],
            ['Grupo 2.1', '2.1'],
            ['6', '6'],
            ['Grupo 10', '10'],
        ];
    }

    public function test_rule_4_a_decimal_is_not_the_same_node_as_its_integer(): void
    {
        $this->assertNotSame(N::normalize('2.1'), N::normalize('2'));
    }

    /** Rule 5 — sub-area labels become slugs. These are staging's verified fact scopes. */
    #[DataProvider('subAreaCases')]
    public function test_rule_5_sub_areas_become_slugs(string $label, string $expected): void
    {
        $this->assertSame($expected, N::normalize($label));
    }

    public static function subAreaCases(): array
    {
        return [
            'area 5' => ['área 5', 'area-5'],
            'resto areas' => ['resto áreas', 'resto-areas'],
            'todas las areas' => ['todas las áreas', 'todas-las-areas'],
            'area cinco' => ['área cinco', 'area-cinco'],
        ];
    }

    /**
     * Rule 5 also carries the prose groups — roughly a third of the gold set's
     * group labels are not numbered at all. They are legitimate groups, they must
     * be representable, and nothing about them is inferred.
     */
    #[DataProvider('proseGroupCases')]
    public function test_rule_5_prose_groups_keep_their_label_as_their_code(string $label, string $expected): void
    {
        $this->assertSame($expected, N::normalize($label));
        $this->assertSame(N::RULE_SLUG, N::explain($label)['rule']);
    }

    public static function proseGroupCases(): array
    {
        return [
            ['Obreros y subalternos', 'obreros-y-subalternos'],
            ['Técnicos titulados', 'tecnicos-titulados'],
            ['Emprendedores', 'emprendedores'],
            ['Personal técnico y administrativo', 'personal-tecnico-y-administrativo'],
        ];
    }

    /**
     * A COMPOUND label is deliberately NOT decomposed. One label is one node;
     * a fact spanning two groups is expressed by binding it to two nodes
     * (`reference_fact_group_scopes`), not by a node meaning "1 and 2".
     */
    public function test_a_compound_label_is_not_split_into_two_nodes(): void
    {
        $this->assertSame('1-y-2', N::normalize('Grupos 1 y 2'));
        $this->assertNotSame('1', N::normalize('Grupos 1 y 2'));
        $this->assertNotSame('2', N::normalize('Grupos 1 y 2'));
    }

    /** Rule 6 — nothing at all in, nothing invented out. */
    #[DataProvider('emptyCases')]
    public function test_rule_6_empty_and_punctuation_only_labels_yield_no_code(?string $label): void
    {
        $this->assertSame('', N::normalize($label));
        $this->assertSame(N::RULE_EMPTY, N::explain($label)['rule']);
    }

    public static function emptyCases(): array
    {
        return [[null], [''], ['   '], ['.'], [':'], ['-'], ['()']];
    }

    /** Normalization is idempotent — running it on its own output changes nothing. */
    #[DataProvider('idempotenceCases')]
    public function test_normalization_is_idempotent(string $label): void
    {
        $once = N::normalize($label);
        $this->assertSame($once, N::normalize($once));
    }

    public static function idempotenceCases(): array
    {
        return [
            ['Grupo 2'], ['Grupo IV'], ['área 5'], ['resto áreas'], ['2.1'],
            ['Obreros y subalternos'], ['Grupos 1 y 2'],
            ['Grupo Prof. 1.º'], ['Grupo Profesional Primero'],
        ];
    }

    // ── Phase 2 addition: one group, however the convenio spells it ─────────

    /**
     * Found while reading Hostelería Navarra's actual text for Phase 2, not
     * imagined: article 19 prints "Grupo Prof. 1.º", the verified reference
     * fact for that same rule writes "Grupo 1", and the annex writes
     * "GRUPO PROFESIONAL PRIMERO". Before this, those produced `prof-1`, `1`
     * and `profesional-primero` — three nodes for one group, which an exact
     * matcher would treat as three different groups. That is the bug this class
     * exists to prevent, re-entering through typography.
     */
    #[DataProvider('oneGroupManySpellingsCases')]
    public function test_every_spelling_of_one_group_yields_one_key(string $label, string $expected): void
    {
        $this->assertSame($expected, N::normalize($label), $label);
    }

    public static function oneGroupManySpellingsCases(): array
    {
        return [
            'article, ordinal marker' => ['Grupo Prof. 1.º', '1'],
            'article, no marker' => ['Grupo Prof. 1', '1'],
            'reference fact' => ['Grupo 1', '1'],
            'annex, ordinal word' => ['GRUPO PROFESIONAL PRIMERO', '1'],
            'roman' => ['Grupo I', '1'],
            'plural heading' => ['Grupos Profesionales 2', '2'],
            'second group, marker' => ['Grupo Prof. 2.º', '2'],
            'third group, ordinal word' => ['Grupo Profesional Tercero', '3'],
            'feminine ordinal' => ['Grupo Profesional Segunda', '2'],
            'sub-area keeps its ordinal marker stripped' => ['área 5.ª', 'area-5'],
        ];
    }

    /**
     * The widening must not swallow a group whose NAME merely begins with a
     * qualifier word: a convenio with a prose group called "Profesionales de
     * oficio" still has to be representable.
     */
    public function test_a_prose_group_name_is_not_mistaken_for_a_qualifier(): void
    {
        $this->assertSame('profesionales-de-oficio', N::normalize('Profesionales de oficio'));
        $this->assertSame('tecnicos-titulados', N::normalize('Técnicos titulados'));
    }
}
