<?php

namespace App\Console\Commands;

use App\Models\Convenio;
use App\Models\ConvenioJobCategory;
use App\Models\Employee;
use App\Models\Sector;
use App\Models\Territory;
use App\Support\TerritoryCatalog;
use App\Support\TextNormalizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Imports the convenio registry from 01_listado_convenios.xlsx into the
 * controlled vocabulary + registry (territories, sectors, convenios).
 *
 * - Idempotent: matches on natural keys (territory level+code/name, sector
 *   normalized name, convenio numero); re-running an unchanged sheet creates
 *   nothing new.
 * - Preserves multi-value headline cells verbatim in convenios.notes; only sets
 *   the typed numeric column when the cell is a single clean number.
 * - Populates Basque/Spanish territory aliases (Bizkaia/Vizcaya, Gipuzkoa/
 *   Guipúzcoa, Araba/Álava, …) so the filename parser never false-conflicts.
 * - Supersedes the Sprint 0 DEV FIXTURE rows once the real registry is in.
 *
 * Does NOT populate convenio_job_categories (deferred — comes from salary tables).
 */
class RegistryImport extends Command
{
    protected $signature = 'registry:import {path? : path to the registry .xlsx (defaults to config registry.xlsx_path)}';

    protected $description = 'Import the convenio registry (LABOUR AGREEMENTS sheet) into territories/sectors/convenios. Idempotent.';

    private const FIXTURE_LABEL = 'DEV FIXTURE — placeholder';

    public function handle(): int
    {
        $path = $this->argument('path') ?? (string) config('registry.xlsx_path');
        $sheetName = (string) config('registry.sheet');

        if (! is_file($path)) {
            $this->error("Registry file not found: {$path}");

            return self::FAILURE;
        }

        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($path);
        $sheet = $spreadsheet->getSheetByName($sheetName);
        if ($sheet === null) {
            $this->error("Sheet '{$sheetName}' not found. Sheets: ".implode(', ', $spreadsheet->getSheetNames()));

            return self::FAILURE;
        }

        $rows = $sheet->toArray(null, true, false, false);
        if (count($rows) < 2) {
            $this->error('Sheet has no data rows.');

            return self::FAILURE;
        }

        // Build a header → column-index map (parse by header name, not letter).
        $header = array_map(fn ($h) => TextNormalizer::key((string) $h), $rows[0]);
        $col = array_flip($header);
        foreach (['NUMERO', 'CONVENIO', 'PROVINCIA'] as $required) {
            if (! isset($col[$required])) {
                $this->error("Missing required column '{$required}'. Found: ".implode(', ', $header));

                return self::FAILURE;
            }
        }
        $idxNumero = $col['NUMERO'];
        $idxName = $col['CONVENIO'];
        $idxProvincia = $col['PROVINCIA'];
        $idxAnnual = $col['HORAS ANUALES'] ?? null;
        $idxWeekly = $col['HORAS SEMANA'] ?? null;
        $idxA3 = $col['NUMERO A3'] ?? null;
        $idxItComp = $col['COMPLEMENTO IT'] ?? null;

        $counts = ['territories_created' => 0, 'sectors_created' => 0, 'convenios_created' => 0, 'convenios_updated' => 0, 'rows' => 0];
        $flagged = []; // ambiguous level classifications needing human confirmation (ADR-0011)
        $merges = []; // duplicate-numero rows collapsed into one convenio (ADR-0011)

        DB::transaction(function () use ($rows, $idxNumero, $idxName, $idxProvincia, $idxAnnual, $idxWeekly, $idxA3, $idxItComp, &$counts, &$flagged, &$merges) {
            // 1) Ensure the canonical territory vocabulary (names + curated aliases).
            $counts['territories_created'] += $this->upsertCatalogTerritories();

            // 2) Group rows by numero. The registry contains real duplicate-numero
            //    rows (one convenio under a formal + colloquial title). The registry
            //    is treated as one-convenio-per-numero; collapsing duplicates is a
            //    deliberate, lossless, logged decision (ADR-0011), never a silent
            //    updateOrCreate overwrite.
            $groups = []; // numero => list<rowData> in sheet order
            foreach (array_slice($rows, 1) as $row) {
                $numero = $this->str($row[$idxNumero] ?? null);
                $name = $this->str($row[$idxName] ?? null);
                $provincia = $this->str($row[$idxProvincia] ?? null);
                if ($numero === '' && $name === '') {
                    continue; // blank row
                }
                $counts['rows']++;

                [$annual, $annualRaw] = $this->parseNumeric($idxAnnual !== null ? ($row[$idxAnnual] ?? null) : null);
                [$weekly, $weeklyRaw] = $this->parseNumeric($idxWeekly !== null ? ($row[$idxWeekly] ?? null) : null);

                $groups[$numero][] = [
                    'name' => $name,
                    'provincia' => $provincia,
                    'annual_hours' => $annual,
                    'weekly_hours' => $weekly,
                    'numero_a3' => $idxA3 !== null ? ($this->str($row[$idxA3] ?? null) ?: null) : null,
                    'it_complement' => $idxItComp !== null ? ($this->str($row[$idxItComp] ?? null) ?: null) : null,
                    'notes' => $this->buildNotes($annualRaw, $weeklyRaw),
                ];
            }

            // 3) Merge each numero group into a single convenio.
            foreach ($groups as $numero => $groupRows) {
                $provincia = $this->firstNonEmpty(array_column($groupRows, 'provincia'));
                $territory = $this->resolveTerritory($numero, $provincia, $counts, $flagged);

                // Canonical name: prefer the more formal/longer title; fold every
                // other distinct spelling into aliases so no name is lost.
                $distinctNames = $this->distinctNames(array_column($groupRows, 'name'));
                $canonicalName = $this->chooseCanonicalName($distinctNames);
                $nameAliases = array_values(array_filter(
                    $distinctNames,
                    fn ($n) => TextNormalizer::key($n) !== TextNormalizer::key($canonicalName),
                ));

                $sector = $this->resolveSector($canonicalName, $provincia, $territory, $counts);

                // Per-field collapse: prefer the non-null / more-complete value.
                $existing = Convenio::where('numero', $numero)->first();
                Convenio::updateOrCreate(
                    ['numero' => $numero],
                    [
                        'name' => $canonicalName,
                        // Preserve aliases already stored (idempotent re-runs) + folded names.
                        'aliases' => $this->mergeAliases($existing?->aliases, $nameAliases),
                        'territory_id' => $territory?->id,
                        'sector_id' => $sector?->id,
                        'annual_hours' => $this->firstNonNull(array_column($groupRows, 'annual_hours')),
                        'weekly_hours' => $this->firstNonNull(array_column($groupRows, 'weekly_hours')),
                        'numero_a3' => $this->firstNonNull(array_column($groupRows, 'numero_a3')),
                        'it_complement' => $this->firstNonNull(array_column($groupRows, 'it_complement')),
                        'notes' => $this->firstNonNull(array_column($groupRows, 'notes')),
                    ],
                );
                $existing ? $counts['convenios_updated']++ : $counts['convenios_created']++;

                if (count($groupRows) > 1) {
                    $merges[] = [
                        'numero' => $numero,
                        'rows_merged' => count($groupRows),
                        'canonical_name' => $canonicalName,
                        'aliases' => $nameAliases,
                        'it_complement' => $this->firstNonNull(array_column($groupRows, 'it_complement')),
                    ];
                }
            }
        });

        $this->supersedeFixture();

        $this->info('Registry import complete:');
        foreach ($counts as $k => $v) {
            $this->line("  {$k}: {$v}");
        }

        if ($merges !== []) {
            $this->newLine();
            $this->warn('Duplicate-numero rows MERGED into one convenio ('.count($merges).'):');
            foreach ($merges as $m) {
                $aliasList = $m['aliases'] === [] ? '[]' : '["'.implode('", "', $m['aliases']).'"]';
                $this->line("  numero {$m['numero']}: merged {$m['rows_merged']} rows; canonical name \"{$m['canonical_name']}\"; aliases {$aliasList}; retained COMPLEMENTO IT ".var_export($m['it_complement'], true));
                Log::warning('registry:import duplicate numero merged', $m);
            }
        }

        if ($flagged !== []) {
            $this->newLine();
            $this->warn('Territory level classifications needing HUMAN CONFIRMATION ('.count($flagged).'):');
            foreach ($flagged as $f) {
                $line = "  numero {$f['numero']} (prefix {$f['prefix']}, PROVINCIA \"{$f['provincia']}\") → classified {$f['level']} — {$f['reason']}";
                $this->line($line);
                Log::warning('registry:import ambiguous territory level', $f);
            }
        }

        return self::SUCCESS;
    }

    /** @param list<string> $values */
    private function firstNonEmpty(array $values): string
    {
        foreach ($values as $v) {
            if (trim((string) $v) !== '') {
                return (string) $v;
            }
        }

        return '';
    }

    /**
     * @param  list<mixed>  $values
     * @return mixed first value that is not null
     */
    private function firstNonNull(array $values): mixed
    {
        foreach ($values as $v) {
            if ($v !== null) {
                return $v;
            }
        }

        return null;
    }

    /**
     * Distinct names by normalized key, preserving sheet order, dropping blanks.
     *
     * @param  list<string>  $names
     * @return list<string>
     */
    private function distinctNames(array $names): array
    {
        $out = [];
        $seen = [];
        foreach ($names as $name) {
            $name = trim((string) $name);
            $key = TextNormalizer::key($name);
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $name;
        }

        return $out;
    }

    /**
     * Canonical name = the more formal/longer title (longest wins; alphabetical
     * tie-break for determinism, so re-runs are stable).
     *
     * @param  list<string>  $names
     */
    private function chooseCanonicalName(array $names): string
    {
        if ($names === []) {
            return '';
        }
        usort($names, function (string $a, string $b) {
            $byLen = mb_strlen($b) <=> mb_strlen($a);

            return $byLen !== 0 ? $byLen : strcmp($a, $b);
        });

        return $names[0];
    }

    private function upsertCatalogTerritories(): int
    {
        $created = 0;
        foreach (TerritoryCatalog::all() as $t) {
            $territory = Territory::where('level', $t['level'])
                ->where(fn ($q) => $q->where('name', $t['name'])->orWhere('code', $t['code']))
                ->first();

            if ($territory === null) {
                $territory = new Territory(['level' => $t['level']]);
                $created++;
            }
            $territory->code = $t['code'];
            $territory->name = $t['name'];
            $territory->level = $t['level'];
            $territory->aliases = $this->mergeAliases($territory->aliases, $t['aliases']);
            $territory->save();
        }

        return $created;
    }

    private function resolveTerritory(string $numero, string $provincia, array &$counts, array &$flagged): ?Territory
    {
        $prefix = substr($numero, 0, 2);

        // Authoritative: numero-prefix range rule. PROVINCIA is a cross-check.
        $prefixLevel = TerritoryCatalog::levelFromPrefix($prefix);
        $provinciaLevel = TerritoryCatalog::levelFromProvincia($provincia);

        if ($prefixLevel === null) {
            // Prefix outside all known ranges — do NOT silently default; flag it
            // and fall back to the PROVINCIA cross-check for the creation level.
            $level = $provinciaLevel;
            $flagged[] = [
                'numero' => $numero, 'prefix' => $prefix, 'provincia' => $provincia,
                'level' => $level,
                'reason' => "prefix outside known ranges (99 / 01–52 / 53–98); fell back to PROVINCIA-implied level '{$provinciaLevel}'",
            ];
        } else {
            $level = $prefixLevel; // prefix wins
            if ($prefixLevel !== $provinciaLevel) {
                $flagged[] = [
                    'numero' => $numero, 'prefix' => $prefix, 'provincia' => $provincia,
                    'level' => $level,
                    'reason' => "prefix-derived level '{$prefixLevel}' disagrees with PROVINCIA-implied level '{$provinciaLevel}'",
                ];
            }
        }

        $code = $prefix;

        $territory = Territory::where('level', $level)->where('code', $code)->first();
        if ($territory === null) {
            // Genuinely new scope in the sheet — the import is a deliberate admin
            // action, so it may create vocabulary (the parser never does).
            $territory = Territory::create([
                'code' => $code,
                'name' => ucwords(mb_strtolower($provincia)),
                'level' => $level,
                'aliases' => [$provincia],
            ]);
            $counts['territories_created']++;
        } else {
            // Absorb the sheet's PROVINCIA spelling as an alias.
            $territory->aliases = $this->mergeAliases($territory->aliases, [$provincia]);
            $territory->save();
        }

        return $territory;
    }

    private function resolveSector(string $convenioTitle, string $provincia, ?Territory $territory, array &$counts): ?Sector
    {
        $sectorName = $this->deriveSectorName($convenioTitle, $provincia, $territory);
        if ($sectorName === '') {
            return null;
        }
        $key = TextNormalizer::key($sectorName);

        // Match against existing sectors' name + aliases.
        foreach (Sector::all() as $s) {
            if (TextNormalizer::key($s->name) === $key) {
                return $s;
            }
            foreach ((array) $s->aliases as $alias) {
                if (TextNormalizer::key($alias) === $key) {
                    return $s;
                }
            }
        }

        $counts['sectors_created']++;

        return Sector::create(['name' => $sectorName, 'aliases' => []]);
    }

    /**
     * The CONVENIO column mixes the activity sector with a trailing scope word
     * (e.g. "DEPORTE ASTURIAS", "OCIO EDUCATIVO Y ANIMACION ANDALUCIA"). Strip a
     * trailing token that matches the row's territory (name/alias/PROVINCIA) so
     * the sector vocabulary is the activity, not the activity+place.
     */
    private function deriveSectorName(string $title, string $provincia, ?Territory $territory): string
    {
        $title = trim(preg_replace('/\s+/', ' ', $title) ?? $title);
        if ($title === '') {
            return '';
        }
        $tokens = explode(' ', $title);
        $lastKey = TextNormalizer::key(end($tokens));

        $scopeKeys = [TextNormalizer::key($provincia)];
        if ($territory) {
            $scopeKeys[] = TextNormalizer::key($territory->name);
            foreach ((array) $territory->aliases as $alias) {
                $scopeKeys[] = TextNormalizer::key($alias);
            }
        }
        if ($lastKey !== '' && in_array($lastKey, $scopeKeys, true)) {
            array_pop($tokens);
        }

        return trim(implode(' ', $tokens));
    }

    /** @return array{0: float|null, 1: string} [numeric|null, rawString] */
    private function parseNumeric(mixed $cell): array
    {
        if ($cell === null) {
            return [null, ''];
        }
        if (is_int($cell) || is_float($cell)) {
            return [(float) $cell, (string) $cell];
        }
        $raw = trim((string) $cell);
        if ($raw === '') {
            return [null, ''];
        }
        // Single clean number only (allow decimal comma). Multi-value cells
        // ("1742 (1698)", "38,5 / 36,5", "39/35") keep numeric NULL — raw in notes.
        $candidate = str_replace(',', '.', $raw);
        if (preg_match('/^[0-9]+(\.[0-9]+)?$/', $candidate) === 1) {
            return [(float) $candidate, $raw];
        }

        return [null, $raw];
    }

    private function buildNotes(string $annualRaw, string $weeklyRaw): ?string
    {
        $parts = [];
        if ($annualRaw !== '') {
            $parts[] = "HORAS ANUALES: {$annualRaw}";
        }
        if ($weeklyRaw !== '') {
            $parts[] = "HORAS SEMANA: {$weeklyRaw}";
        }

        return $parts === [] ? null : implode('; ', $parts);
    }

    /**
     * @param  mixed  $existing
     * @param  list<string>  $add
     * @return list<string>
     */
    private function mergeAliases($existing, array $add): array
    {
        $out = [];
        $seen = [];
        foreach (array_merge((array) $existing, $add) as $alias) {
            $alias = trim((string) $alias);
            if ($alias === '') {
                continue;
            }
            $key = TextNormalizer::key($alias);
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $alias;
        }

        return $out;
    }

    private function str(mixed $value): string
    {
        return trim((string) ($value ?? ''));
    }

    /**
     * Re-point any employee on the DEV FIXTURE convenio to a real convenio, then
     * remove the Sprint 0 fixture rows. Idempotent.
     */
    private function supersedeFixture(): void
    {
        $fixture = Convenio::where('numero', 'DEV-FIXTURE-0001')->first();
        if ($fixture === null) {
            return;
        }

        $real = Convenio::where('numero', '!=', 'DEV-FIXTURE-0001')->orderBy('numero')->first();
        if ($real !== null) {
            Employee::where('convenio_id', $fixture->id)->each(function (Employee $e) use ($real) {
                $e->convenio_id = $real->id;
                $e->territory_id = $real->territory_id;
                $e->job_category_id = null; // job categories not populated this sprint
                $e->save();
            });
        }

        ConvenioJobCategory::where('convenio_id', $fixture->id)->delete();
        $fixture->delete();
        Sector::where('name', self::FIXTURE_LABEL)->delete();

        $this->line('  superseded DEV FIXTURE rows');
    }
}
