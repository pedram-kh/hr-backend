<?php

namespace App\Support;

/**
 * The canonical controlled vocabulary of territorial scopes present in Sedena's
 * registry, with the curated alias sets that let the parser match the
 * Basque/Spanish spelling variation across filenames (Basque), folders
 * (Spanish), and the registry sheet's PROVINCIA column.
 *
 * Single source of truth, used by BOTH the TerritorySeeder (fresh installs /
 * tests) and `registry:import` (which merges the sheet's own spelling into
 * aliases). `code` is the 2-digit numero prefix for provincial scopes, `71` for
 * the Andalucía autonomous community (regional), `99` for Estatal (national).
 *
 * Confirmed against 01_listado_convenios.xlsx: Andalucía COEAS (numero prefix
 * 71, PROVINCIA "ANDALUCIA") is a REGIONAL territory, not a province.
 */
class TerritoryCatalog
{
    /** @return list<array{code:string,name:string,level:string,aliases:list<string>}> */
    public static function all(): array
    {
        return [
            ['code' => '01', 'name' => 'Álava', 'level' => 'provincial', 'aliases' => ['Araba', 'Alava', 'ALABA', 'ARABA']],
            ['code' => '20', 'name' => 'Gipuzkoa', 'level' => 'provincial', 'aliases' => ['Guipúzcoa', 'Guipuzcoa', 'GUIPUZCOA']],
            ['code' => '22', 'name' => 'Huesca', 'level' => 'provincial', 'aliases' => []],
            ['code' => '28', 'name' => 'Madrid', 'level' => 'provincial', 'aliases' => []],
            ['code' => '31', 'name' => 'Navarra', 'level' => 'provincial', 'aliases' => ['Nafarroa']],
            ['code' => '33', 'name' => 'Asturias', 'level' => 'provincial', 'aliases' => ['Principado de Asturias']],
            ['code' => '37', 'name' => 'Salamanca', 'level' => 'provincial', 'aliases' => []],
            ['code' => '39', 'name' => 'Cantabria', 'level' => 'provincial', 'aliases' => []],
            ['code' => '46', 'name' => 'Valencia', 'level' => 'provincial', 'aliases' => ['València']],
            ['code' => '48', 'name' => 'Vizcaya', 'level' => 'provincial', 'aliases' => ['Bizkaia', 'Vizcaia', 'VIZCAIA']],
            ['code' => '71', 'name' => 'Andalucía', 'level' => 'regional', 'aliases' => ['Andalucia', 'ANDALUCIA']],
            ['code' => '99', 'name' => 'Estatal', 'level' => 'national', 'aliases' => ['Nacional', 'ESTATAL']],
        ];
    }

    /**
     * Authoritative level classifier: the **numero-prefix range** rule.
     *   - `99`            → national
     *   - `01`–`52`       → provincial (Spanish provincial code range)
     *   - any other 2-digit prefix (the autonomic range, e.g. Andalucía's `71`)
     *                     → regional
     *   - anything else (non-2-digit / non-numeric, e.g. `00`) → null
     *     (outside all known ranges — caller must flag, never silently default)
     *
     * This does NOT hardcode the two known cases by name, so a new
     * autonomous-community convenio is classified regional from its prefix
     * rather than silently falling through to provincial.
     */
    public static function levelFromPrefix(string $prefix): ?string
    {
        if (! ctype_digit($prefix) || strlen($prefix) !== 2) {
            return null;
        }
        $n = (int) $prefix;

        return match (true) {
            $n === 99 => 'national',
            $n >= 1 && $n <= 52 => 'provincial',
            $n >= 53 && $n <= 98 => 'regional',
            default => null, // e.g. 00
        };
    }

    /**
     * Cross-check only: the level implied by the PROVINCIA string. Used to
     * detect disagreement with the prefix-derived level (the prefix wins); a
     * disagreement is surfaced for human confirmation, not silently resolved.
     * A genuinely new region whose name isn't yet known here will read
     * `provincial` and therefore DISAGREE with its regional prefix → flagged.
     */
    private const KNOWN_REGION_KEYS = ['ANDALUCIA'];

    public static function levelFromProvincia(string $provincia): string
    {
        $key = TextNormalizer::key($provincia);

        return match (true) {
            $key === 'ESTATAL' || $key === 'NACIONAL' => 'national',
            in_array($key, self::KNOWN_REGION_KEYS, true) => 'regional',
            default => 'provincial',
        };
    }
}
