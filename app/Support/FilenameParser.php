<?php

namespace App\Support;

/**
 * Deterministic tier-1 parser: extracts facet *signals* from a filename + its
 * containing folder. Pure string work — no DB, no LLM, no vocabulary creation.
 * Resolving these signals to controlled-vocabulary FKs and cross-checking them
 * against the registry happens in DocumentTagger.
 *
 * Example:
 *   01100635012017_OCIO_EDUCATIVO_ALAVA_20232026_Tablas_2026.pdf
 *   → numero 01100635012017, prefix 01, sector tokens [OCIO, EDUCATIVO],
 *     territory token ALAVA, validity 2023–2026, type salary_tables.
 */
class FilenameParser
{
    /** Doc-type keyword (normalized) → document_types.code. */
    private const TYPE_KEYWORDS = [
        'TABLAS' => 'salary_tables',
        'CAMBIOS' => 'changes',
        'ACUERDO' => 'partial_agreement',
        'PARCIAL' => 'partial_agreement',
        'RESUMEN' => 'summary',
    ];

    /** Tokens that are neither sector nor territory — dropped from the sector phrase. */
    private const FILLER = ['TEXTO', 'TEXT', 'DEF', 'DEFINITIVO', 'VIG', 'VIGENTE', 'PDF', 'Y', 'DE', 'DEL', 'LA', 'EL', 'LOS', 'LAS'];

    /**
     * @param  string  $sourceFilename  the original filename (with extension)
     * @param  string|null  $folderLabel  the top-level folder (territory signal)
     * @param  string|null  $relativePath  full relative path (detects an Antiguo subfolder)
     * @return array<string,mixed>
     */
    public function parse(string $sourceFilename, ?string $folderLabel, ?string $relativePath = null): array
    {
        $base = preg_replace('/\.[A-Za-z0-9]+$/', '', $sourceFilename) ?? $sourceFilename;
        $rawTokens = preg_split('/[_\s]+/', trim($base)) ?: [];
        $tokens = array_values(array_filter($rawTokens, fn ($t) => $t !== ''));

        $isNationalLaw = $this->looksLikeEstatuto($base);

        // numero: a 14-digit (fallback 12+) run.
        $numero = null;
        foreach ($tokens as $t) {
            if (preg_match('/^\d{14}$/', $t) === 1) {
                $numero = $t;
                break;
            }
        }
        if ($numero === null) {
            foreach ($tokens as $t) {
                if (preg_match('/^\d{12,}$/', $t) === 1) {
                    $numero = $t;
                    break;
                }
            }
        }
        $prefix = $numero !== null ? substr($numero, 0, 2) : null;

        [$validityStart, $validityEnd, $fileYear] = $this->parseValidity($tokens);

        $documentTypeCode = $isNationalLaw ? 'national_law' : 'convenio_text';
        foreach ($tokens as $t) {
            $key = TextNormalizer::key($t);
            if (isset(self::TYPE_KEYWORDS[$key]) && ! $isNationalLaw) {
                $documentTypeCode = self::TYPE_KEYWORDS[$key];
                break;
            }
        }

        // Candidate place/sector tokens = alpha tokens that aren't the numero,
        // a year, a doc-type keyword, or filler. (Territory vs sector is decided
        // later by vocabulary resolution.)
        $alphaTokens = [];
        foreach ($tokens as $t) {
            if (preg_match('/^\d+$/', $t) === 1) {
                continue;
            }
            $key = TextNormalizer::key($t);
            if ($key === '' || in_array($key, self::FILLER, true) || isset(self::TYPE_KEYWORDS[$key])) {
                continue;
            }
            $alphaTokens[] = $t;
        }

        return [
            'numero' => $numero,
            'prefix' => $prefix,
            'alpha_tokens' => $alphaTokens,
            'folder_label' => $folderLabel,
            'validity_start' => $validityStart,
            'validity_end' => $validityEnd,
            'validity_provisional' => $validityStart !== null,
            'file_year' => $fileYear,
            'document_type_code' => $documentTypeCode,
            'is_national_law' => $isNationalLaw,
            'is_historical_folder' => $this->hasAntiguo($relativePath, $folderLabel),
            'language' => 'es', // Q7: default es for every document this sprint
        ];
    }

    private function looksLikeEstatuto(string $base): bool
    {
        $key = TextNormalizer::key($base);

        return str_contains($key, 'ESTATUTO') && str_contains($key, 'TRABAJADORES');
    }

    private function hasAntiguo(?string $relativePath, ?string $folderLabel): bool
    {
        foreach ([$relativePath, $folderLabel] as $candidate) {
            if ($candidate !== null && str_contains(TextNormalizer::key($candidate), 'ANTIGUO')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Handles both filename validity formats:
     *   - YYYYYYYY  (e.g. 20232026)
     *   - YYYY_YYYY (e.g. 2024_2026, adjacent 4-digit year tokens)
     * A lone trailing 4-digit year is the file year, not a validity range.
     * Dates are provisional: start → YYYY-01-01, end → YYYY-12-31 (Q6).
     *
     * @param  list<string>  $tokens
     * @return array{0:?string,1:?string,2:?int}
     */
    private function parseValidity(array $tokens): array
    {
        // Format A: a single 8-digit token split into two plausible years.
        foreach ($tokens as $t) {
            if (preg_match('/^(\d{4})(\d{4})$/', $t, $m) === 1) {
                $s = (int) $m[1];
                $e = (int) $m[2];
                if ($this->isYear($s) && $this->isYear($e) && $s <= $e) {
                    return ["{$s}-01-01", "{$e}-12-31", null];
                }
            }
        }

        // Format B: two adjacent 4-digit year tokens.
        $count = count($tokens);
        for ($i = 0; $i < $count - 1; $i++) {
            if (preg_match('/^\d{4}$/', $tokens[$i]) === 1 && preg_match('/^\d{4}$/', $tokens[$i + 1]) === 1) {
                $s = (int) $tokens[$i];
                $e = (int) $tokens[$i + 1];
                if ($this->isYear($s) && $this->isYear($e) && $s <= $e) {
                    return ["{$s}-01-01", "{$e}-12-31", null];
                }
            }
        }

        // Lone trailing year → file year only.
        $fileYear = null;
        foreach ($tokens as $t) {
            if (preg_match('/^\d{4}$/', $t) === 1 && $this->isYear((int) $t)) {
                $fileYear = (int) $t;
            }
        }

        return [null, null, $fileYear];
    }

    private function isYear(int $y): bool
    {
        return $y >= 1900 && $y <= 2100;
    }
}
