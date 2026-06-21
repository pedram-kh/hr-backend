<?php

namespace App\Support;

use App\Models\Convenio;
use App\Models\DocumentType;
use Illuminate\Support\Carbon;

/**
 * Turns FilenameParser signals into resolved controlled-vocabulary FKs and runs
 * conflict/unresolved detection against the imported registry.
 *
 * Hard rules:
 *  - Tags are FKs into controlled vocabulary; we NEVER create vocabulary values.
 *  - Conflict beats confidence (ADR-0002): any contradiction → under_review.
 *  - Two-reason routing (ADR-0011): `conflict` = resolved-but-contradicts
 *    (human-adjudicated); `unresolved` = parser had nothing/unmatched value
 *    (LLM-eligible later). Conflict outranks unresolved.
 */
class DocumentTagger
{
    public function __construct(private VocabularyResolver $vocab) {}

    /**
     * @param  array<string,mixed>  $parsed  output of FilenameParser::parse()
     * @return array<string,mixed>
     */
    public function tag(array $parsed): array
    {
        $isNationalLaw = (bool) $parsed['is_national_law'];

        $docType = DocumentType::where('code', $parsed['document_type_code'])->first()
            ?? DocumentType::where('code', 'convenio_text')->first()
            ?? DocumentType::where('code', 'other')->first();

        $numero = $parsed['numero'];
        $convenio = $numero !== null
            ? Convenio::with(['territory', 'sector'])->where('numero', $numero)->first()
            : null;

        // --- Territory resolution ---
        $prefixTerritory = $this->vocab->territoryByCode($parsed['prefix']);
        $folderTerritory = $this->vocab->territoryByName($parsed['folder_label']);

        $nameTerritories = [];   // token => Territory
        $leftoverTokens = [];    // alpha tokens that are NOT a territory (sector candidates)
        foreach ($parsed['alpha_tokens'] as $tok) {
            $t = $this->vocab->territoryByName($tok);
            if ($t !== null) {
                $nameTerritories[$tok] = $t;
            } else {
                $leftoverTokens[] = $tok;
            }
        }

        $expected = $convenio?->territory ?? $prefixTerritory;
        $territory = $expected
            ?? $folderTerritory
            ?? (! empty($nameTerritories) ? reset($nameTerritories) : null)
            ?? ($isNationalLaw ? $this->vocab->territoryByCode('99') : null);

        // --- Sector resolution (from leftover, non-territory tokens) ---
        // National-law docs (Estatuto) have no sector/convenio — their title
        // tokens are the law name, not a sector to resolve (Q8).
        $sectorPhrase = $isNationalLaw ? '' : trim(implode(' ', $leftoverTokens));
        $parsedSector = $sectorPhrase !== '' ? $this->vocab->sectorByName($sectorPhrase) : null;
        // The convenio's sector is authoritative; parsed sector is a cross-check.
        $sectorId = $convenio?->sector_id ?? $parsedSector?->id;

        $conflictFacets = [];          // [['facet','note']]
        $unresolved = [];              // [['facet','value']]

        // Rule 1 — unknown convenio (a numero was parsed but isn't in the registry).
        if ($numero !== null && $convenio === null) {
            $conflictFacets[] = ['facet' => 'convenio', 'note' => "numero {$numero} not in registry"];
        }

        // Rule 2 — territory disagreement: folder/name territory contradicts the expected one.
        if ($expected !== null) {
            foreach (array_filter([$folderTerritory, ...array_values($nameTerritories)]) as $cand) {
                if ($cand->id !== $expected->id) {
                    $conflictFacets[] = [
                        'facet' => 'territory',
                        'note' => "filename/folder territory {$cand->name} disagrees with {$expected->name}",
                    ];
                    break;
                }
            }
        }

        // Rule 3 — sector disagreement: a resolved parsed sector contradicts the registry's.
        if ($parsedSector !== null && $convenio !== null && $parsedSector->id !== $convenio->sector_id) {
            $conflictFacets[] = ['facet' => 'sector', 'note' => "parsed sector {$parsedSector->name} disagrees with registry"];
        }

        // Rule 4 — unmatched controlled values (variant-or-new candidates → unresolved).
        if ($territory === null && ($parsed['folder_label'] || ! empty($parsed['alpha_tokens']))) {
            $raw = $parsed['folder_label'] ?: implode(' ', $parsed['alpha_tokens']);
            if (trim((string) $raw) !== '') {
                $unresolved[] = ['facet' => 'territory', 'value' => (string) $raw];
            }
        }
        if ($sectorId === null && $sectorPhrase !== '') {
            $unresolved[] = ['facet' => 'sector', 'value' => $sectorPhrase];
        }

        // Rule 5 — parser had nothing to resolve (no numero, not national law).
        if ($numero === null && ! $isNationalLaw) {
            $unresolved[] = ['facet' => 'convenio', 'value' => $parsed['source_filename'] ?? '(filename)'];
        }

        // --- Lifecycle facets ---
        $retrievalStatus = 'active';
        if ($parsed['is_historical_folder']) {
            $retrievalStatus = 'historical'; // Antiguo subfolder — never current (catch #8)
        } elseif ($parsed['validity_end'] !== null && Carbon::parse($parsed['validity_end'])->isPast()) {
            $retrievalStatus = 'historical';
        }
        $authorityLevel = $isNationalLaw ? 'national_law' : 'official_convenio';

        // --- Review routing (conflict outranks unresolved) ---
        $review = null;
        if (! empty($conflictFacets)) {
            $review = [
                'type' => 'conflict',
                'reason' => 'conflict',
                'conflict_facets' => $conflictFacets,
                'raw_unmatched_values' => array_merge(
                    array_map(fn ($c) => ['facet' => $c['facet'], 'value' => $c['note']], $conflictFacets),
                    $unresolved,
                ),
                'note' => implode('; ', array_map(fn ($c) => $c['note'], $conflictFacets)),
            ];
        } elseif (! empty($unresolved)) {
            $review = [
                'type' => 'tag_review',
                'reason' => 'unresolved',
                'conflict_facets' => [],
                'raw_unmatched_values' => $unresolved,
                'note' => 'Parser could not resolve: '.implode(', ', array_map(fn ($u) => "{$u['facet']}={$u['value']}", $unresolved)),
            ];
        }

        $clean = $review === null && $parsed['validity_start'] !== null && ($convenio !== null || $isNationalLaw);
        $confidence = $review !== null ? 0.5 : ($clean ? 1.0 : 0.8);

        // --- Resolved facets for provenance ---
        $facets = [['facet' => 'document_type', 'new_value' => $docType?->code, 'confidence' => 1.0]];
        if ($convenio !== null) {
            $facets[] = ['facet' => 'convenio', 'new_value' => $convenio->numero, 'confidence' => $confidence];
        }
        if ($territory !== null) {
            $facets[] = ['facet' => 'territory', 'new_value' => $territory->code ?? $territory->name, 'confidence' => $confidence];
        }
        if ($sectorId !== null) {
            $facets[] = ['facet' => 'sector', 'new_value' => $convenio?->sector?->name ?? $parsedSector?->name, 'confidence' => $confidence];
        }
        if ($parsed['validity_start'] !== null) {
            $facets[] = [
                'facet' => 'validity',
                'new_value' => "{$parsed['validity_start']}..{$parsed['validity_end']}",
                'confidence' => $confidence,
            ];
        }

        return [
            'convenio_id' => $convenio?->id,
            'territory_id' => $territory?->id,
            'sector_id' => $sectorId,
            'document_type_id' => $docType?->id,
            'validity_start' => $parsed['validity_start'],
            'validity_end' => $parsed['validity_end'],
            'retrieval_status' => $retrievalStatus,
            'authority_level' => $authorityLevel,
            'language' => $parsed['language'],
            'tagging_status' => $review !== null ? 'under_review' : 'auto_proposed',
            'tagging_confidence' => $confidence,
            'facets' => $facets,
            'review' => $review,
        ];
    }
}
