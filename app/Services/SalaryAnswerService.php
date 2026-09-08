<?php

namespace App\Services;

use App\Models\ConvenioJobCategory;
use App\Models\Document;
use App\Models\Employee;
use App\Models\SalaryTable;
use App\Models\SalaryTableRow;
use Illuminate\Support\Carbon;

/**
 * Salary-in-chat (Sprint 2b-2 §3) — SQL-grounded, exact, year-aligned (ADR-0006).
 *
 * Correction-salary-01: the answer states ONLY figures that are STORED. It never
 * computes one from another — a monthly figure is quoted only when the source
 * table had a monthly column (`base_salary_monthly`), and the payment count only
 * when the source stated it (`pagas_count`). When there is no stored monthly the
 * answer gives the annual and says how many pagas the table is expressed over if
 * that is known, and simply omits the monthly. The previous behaviour (hr-ai
 * deriving `gross_annual / 14`) quoted convenio 15 a monthly of 2.392,24 € where
 * its own gazette prints 2.232,75 €.
 *
 * Each monthly figure is NAMED BY ITS SOURCE COLUMN, never generically: a sheet
 * can print several monthly quantities that are not interchangeable (convenio 10
 * prints `salario base` 1.183,34 and `bruto mes` 1.771,64), so the answer states
 * every one it has, each with the header the table gives it. See monthlyFigures().
 *
 * A salary figure comes ONLY from the typed `salary_table_rows` cell, bound to
 * its job category and year BY CONSTRUCTION — never parsed from a prose/embedded-
 * table chunk (the structural antidote to the Q5 misattribution, where a 2025 row
 * was reported as 2024 from an embedded wage-table prose chunk). The answer states
 * the category + year EXPLICITLY and cites the salary-table source document
 * (`message_citations` with `chunk_id = null`). No LLM, no /synthesise, no /ground.
 *
 * Category resolution is single-turn (§4): profile category → else a CONSTRAINED
 * pick from the convenio's `convenio_job_categories` (FK-validated, free text
 * impossible), treated as UNVERIFIED and shown in the answer ("según tu
 * indicación") → else, when no table exists at all, escalate (don't loop asking).
 *
 * Coverage gap (ADR-0014) → escalate `salary_coverage_gap`, never guess.
 *
 * This SUPERSEDES the Correction-02 blanket salary escalation (`salary_not_in_chat`).
 */
class SalaryAnswerService
{
    public const OUTCOME_ANSWER = 'answer';

    public const OUTCOME_NEEDS_CATEGORY = 'needs_category';

    public const OUTCOME_ESCALATE = 'escalate';

    /** Surfaced when the convenio/year/category has no salary row (coverage gap). */
    public const COVERAGE_GAP_MESSAGE = 'Todavía no tengo tu tabla salarial para ese año o esa '
        .'categoría en mi base de datos estructurada, y no quiero darte una cifra que no pueda '
        .'confirmar de forma exacta. Te derivo con una persona del equipo de Recursos Humanos.';

    /**
     * Answer a salary question from SQL, or escalate / ask for the category.
     *
     * @param  int|null  $selectedJobCategoryId  an unverified category the employee picked (follow-up)
     * @return array{outcome:string, answer:string, citations:list<array<string,mixed>>, escalation_reason:?string, categories:list<array<string,mixed>>, salary:array<string,mixed>}
     */
    public function answer(Employee $employee, Carbon $asOfDate, ?int $selectedJobCategoryId = null): array
    {
        $employee->loadMissing('convenio');
        $convenio = $employee->convenio;

        $salary = [
            'convenio_id' => $convenio?->id,
            'as_of_date' => $asOfDate->toDateString(),
        ];

        if (! $convenio) {
            // No convenio on the profile → no structured salary scope. Escalate.
            $salary['outcome'] = 'escalate';
            $salary['note'] = 'no convenio on profile';

            return $this->escalate($salary);
        }

        // --- Resolve the applicable salary table (year-aligned, §9 C) -----------
        // Coverage-gap check runs BEFORE prompting for a category (§4): asking the
        // employee can't conjure a document the system doesn't have.
        [$table, $yearSelection] = $this->resolveTable($convenio->id, $asOfDate->year);

        if ($table === null) {
            $salary['outcome'] = 'escalate';
            $salary['year_selection'] = $yearSelection; // 'no_table' | 'future_only'
            $salary['note'] = $yearSelection === 'future_only'
                ? 'only a not-yet-effective (future) salary table exists — escalate, do not quote'
                : 'no salary table for this convenio (coverage gap — salary may be PDF-only)';

            return $this->escalate($salary);
        }

        $salary['table_id'] = $table->id;
        $salary['year'] = $table->year;
        $salary['year_selection'] = $yearSelection;

        // --- Resolve the job category (§4: profile → constrained pick → …) ------
        [$category, $categorySource, $categories] = $this->resolveCategory($employee, $convenio->id, $selectedJobCategoryId);

        if ($category === null && $categorySource === 'needs_pick') {
            // A table exists but we don't know the employee's category → ask, with
            // a CONSTRAINED pick from the convenio's actual categories (not free text).
            $salary['outcome'] = 'needs_category';
            $salary['note'] = 'profile category absent/ambiguous — constrained pick offered';

            return [
                'outcome' => self::OUTCOME_NEEDS_CATEGORY,
                'answer' => 'Para darte la cifra exacta necesito tu categoría profesional. '
                    .'Elige la que te corresponde en tu convenio:',
                'citations' => [],
                'escalation_reason' => null,
                'categories' => $categories,
                'salary' => $salary,
            ];
        }

        if ($category === null) {
            // A pick was supplied but didn't resolve to a category in this convenio
            // (FK guard) — treat as a gap rather than guessing.
            $salary['outcome'] = 'escalate';
            $salary['note'] = 'selected category not valid for this convenio';

            return $this->escalate($salary);
        }

        $salary['job_category_id'] = $category->id;
        $salary['category_source'] = $categorySource; // 'profile' | 'picked_unverified'

        // --- Query the typed row (exact, by construction) -----------------------
        $row = SalaryTableRow::where('salary_table_id', $table->id)
            ->where('job_category_id', $category->id)
            ->first();

        if ($row === null) {
            // Covered convenio/year but no row for this category → coverage gap.
            $salary['outcome'] = 'escalate';
            $salary['note'] = 'no salary row for this category in the resolved table';

            return $this->escalate($salary);
        }

        $salary['outcome'] = 'answer';
        $salary['row'] = [
            'gross_annual' => $row->gross_annual,
            'base_salary_monthly' => $row->base_salary_monthly,
            'base_salary_monthly_label' => $row->base_salary_monthly_label,
            'pagas_count' => $row->pagas_count,
            'hourly_rate' => $row->hourly_rate,
            'extra_pay' => $row->extra_pay,
            'night_plus' => $row->night_plus,
        ];

        $answerText = $this->composeAnswer($category, $categorySource, $table, $row);
        $citations = $this->salaryCitation($table);

        return [
            'outcome' => self::OUTCOME_ANSWER,
            'answer' => $answerText,
            'citations' => $citations,
            'escalation_reason' => null,
            'categories' => [],
            'salary' => $salary,
        ];
    }

    /**
     * Resolve the applicable salary table for a convenio as of a year (§9 C):
     * prefer the EXACT year; else the most-recent table with `year <= as-of`
     * (stated explicitly in the answer); if only FUTURE tables exist (all
     * `year > as-of`) → none (escalate — never quote a not-yet-effective figure).
     *
     * @return array{0: ?SalaryTable, 1: string} [table|null, year_selection]
     */
    private function resolveTable(int $convenioId, int $asOfYear): array
    {
        $exact = SalaryTable::where('convenio_id', $convenioId)->where('year', $asOfYear)->first();
        if ($exact) {
            return [$exact, 'exact'];
        }

        $mostRecentLe = SalaryTable::where('convenio_id', $convenioId)
            ->whereNotNull('year')
            ->where('year', '<=', $asOfYear)
            ->orderByDesc('year')
            ->first();
        if ($mostRecentLe) {
            return [$mostRecentLe, 'most_recent_le'];
        }

        // No usable (<= as-of) table. Distinguish "only future tables exist"
        // (escalate — don't quote a not-yet-effective figure, §9 C) from "no
        // table at all / unusable" (coverage gap). Both escalate; the note differs.
        $hasFuture = SalaryTable::where('convenio_id', $convenioId)
            ->whereNotNull('year')
            ->where('year', '>', $asOfYear)
            ->exists();

        return [null, $hasFuture ? 'future_only' : 'no_table'];
    }

    /**
     * Resolve the job category for the lookup (§4).
     *  1. profile category (verified) → ['profile'].
     *  2. an unverified pick the employee supplied, FK-validated to the convenio
     *     → ['picked_unverified'].
     *  3. neither → ['needs_pick'] with the constrained list of categories.
     *
     * @return array{0: ?ConvenioJobCategory, 1: string, 2: list<array<string,mixed>>}
     */
    private function resolveCategory(Employee $employee, int $convenioId, ?int $selectedJobCategoryId): array
    {
        if ($employee->job_category_id) {
            $cat = ConvenioJobCategory::where('id', $employee->job_category_id)
                ->where('convenio_id', $convenioId)
                ->first();
            if ($cat) {
                return [$cat, 'profile', []];
            }
        }

        if ($selectedJobCategoryId !== null) {
            // FK-constrained to THIS convenio — a self-declared free-text category
            // is structurally impossible; an out-of-convenio id resolves to null.
            $cat = ConvenioJobCategory::where('id', $selectedJobCategoryId)
                ->where('convenio_id', $convenioId)
                ->first();

            return [$cat, $cat ? 'picked_unverified' : 'invalid_pick', []];
        }

        $categories = ConvenioJobCategory::where('convenio_id', $convenioId)
            ->orderBy('group_code')
            ->orderBy('name')
            ->get(['id', 'name', 'group_code'])
            ->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'group_code' => $c->group_code])
            ->all();

        return [null, 'needs_pick', $categories];
    }

    /** Compose the exact, structurally-aligned salary answer (category + year stated). */
    private function composeAnswer(ConvenioJobCategory $category, string $categorySource, SalaryTable $table, SalaryTableRow $row): string
    {
        $catLabel = $category->name;
        if ($category->group_code) {
            $catLabel .= " (grupo {$category->group_code})";
        }
        // The picked category is UNVERIFIED — disclose it so any mismatch is visible.
        $disclosure = $categorySource === 'picked_unverified' ? ' (según tu indicación)' : '';

        $parts = [];
        if ($row->gross_annual !== null) {
            $annual = 'bruto anual de '.$this->money($row->gross_annual);
            // With no stored monthly, the pagas count is the most that can be
            // said honestly — it is a STATED fact about the table, never a
            // divisor this answer is allowed to apply (Correction-salary-01).
            if ($row->base_salary_monthly === null && $row->pagas_count !== null) {
                $annual .= " (tabla expresada en {$row->pagas_count} pagas)";
            }
            $parts[] = $annual;
        }
        foreach ($this->monthlyFigures($row) as $monthly) {
            $parts[] = $monthly;
        }
        if ($row->extra_pay !== null) {
            $parts[] = 'pagas extra de '.$this->money($row->extra_pay);
        }
        if ($row->night_plus !== null) {
            $parts[] = 'plus de nocturnidad de '.$this->money($row->night_plus);
        }
        if ($row->hourly_rate !== null) {
            $parts[] = 'precio/hora de '.$this->moneyHour($row->hourly_rate);
        }

        $figures = $parts === [] ? 'no consta una cifra de salario en la fila de la tabla' : implode('; ', $parts);

        return "Para la categoría {$catLabel}{$disclosure}, según la tabla salarial de {$table->year} "
            ."de tu convenio: {$figures}. (Cifra exacta de la tabla salarial estructurada; "
            .'si tu categoría no es la indicada, dímelo y la ajusto.)';
    }

    /**
     * Every monthly figure the source prints for this row, each named the way the
     * source names it (Correction-salary-01 follow-up).
     *
     * A convenio does not print "the monthly salary": convenio 10's sheet prints a
     * `salario base` of 1.183,34 AND a `bruto mes` of 1.771,64 (the base plus its
     * prorated extras), and COEAS Navarra prints `14 pagas` next to `12 pagas`.
     * Those are different quantities, so a generic "salario mensual de …" states
     * something the employee cannot check against a payslip. Each figure is
     * therefore labelled with its own column header, and when the table prints
     * more than one, all of them are stated.
     *
     * The extra figures are read from `raw_values`, which holds the source's cells
     * verbatim — so this widens WHAT IS QUOTED, never what is computed. Two
     * deliberate limits: they are only surfaced when the typed monthly exists (a
     * sheet whose monthly is ambiguous — the multi-year block of ADR-0027 — stays
     * silent rather than offering four figures and no way to choose), and a
     * suffixed duplicate key ("14 pagas (2)", the second year of such a block) is
     * never quoted.
     *
     * @return list<string>
     */
    private function monthlyFigures(SalaryTableRow $row): array
    {
        if ($row->base_salary_monthly === null) {
            return [];
        }

        $typed = (float) $row->base_salary_monthly;
        $out = [$this->phraseMonthly($row->base_salary_monthly_label, $typed, $row->pagas_count)];
        $seen = [$this->cents($typed)];

        foreach ($row->raw_values ?? [] as $header => $value) {
            $label = (string) $header;
            if (preg_match('/\s\(\d+\)$/', $label)) {
                continue; // the second year of a multi-year block — not this table's
            }
            if (! $this->isMonthlyHeader($label)) {
                continue;
            }
            $amount = $this->parseAmount($value);
            if ($amount === null || in_array($this->cents($amount), $seen, true)) {
                continue;
            }
            $seen[] = $this->cents($amount);
            $out[] = $this->phraseMonthly($label, $amount, null);
        }

        return $out;
    }

    /** True when a source header names a monthly amount (strict — a complement or a plus is not one). */
    private function isMonthlyHeader(string $header): bool
    {
        $n = $this->normalizeHeader($header);

        return (bool) preg_match('/^\d{1,2} pagas$/', $n)
            || (bool) preg_match('/^bruto ?\/? ?mes\b/', $n)
            || (bool) preg_match('/^(sb|salario base|sueldo base|sueldo mensual|salario mensual|base mensual|salario mes|base mes)\b/', $n);
    }

    /**
     * Name a monthly figure using the source column's own wording. An unrecognized
     * header is QUOTED rather than paraphrased — better a verbatim «bruto/mes 14
     * pagas» than a guess at what it means.
     */
    private function phraseMonthly(?string $header, float $amount, ?int $pagasCount): string
    {
        $n = $header === null ? '' : $this->normalizeHeader($header);
        $statesPagas = (bool) preg_match('/\d{1,2} pagas/', $n);

        if (preg_match('/^(\d{1,2}) pagas$/', $n, $m)) {
            $name = "importe mensual en {$m[1]} pagas";
        } elseif (preg_match('/^bruto ?\/? ?mes\b/', $n)) {
            $name = 'bruto mensual';
        } elseif ($n === '' || preg_match('/^(sb|salario base|sueldo base|sueldo mensual|salario mensual|base mensual|salario mes|base mes)\b/', $n)) {
            // No stored label: the typed column only ever accepts a base-monthly
            // header, so this is the narrowest true statement — rows imported
            // before the label existed keep it.
            $name = 'salario base mensual';
        } else {
            $name = '«'.trim((string) $header).'»';
        }

        // The count is a stated fact about the table, never a divisor — and it is
        // not repeated when the column name already says it.
        $payments = $pagasCount !== null && ! $statesPagas ? " en {$pagasCount} pagas" : '';

        return $name.' de '.$this->money($amount).$payments;
    }

    /** Lowercase, unaccented, whitespace-collapsed, trailing unit/currency markers dropped. */
    private function normalizeHeader(string $header): string
    {
        $s = str_replace("\n", ' ', $header);
        $s = (string) preg_replace('/\s+/u', ' ', $s);
        $s = mb_strtolower(trim($s));
        $s = strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);

        return trim((string) preg_replace('/\s*\((?:€|eur|euros?|mes|mensual)\)\s*$/u', '', $s));
    }

    /** A raw cell → float, accepting both 1.183,34 and 1183.34. Null when it isn't a number. */
    private function parseAmount($value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        if (! is_string($value)) {
            return null;
        }
        $s = trim(preg_replace('/[^\d,.\-]/u', '', $value) ?? '');
        if ($s === '') {
            return null;
        }
        if (preg_match('/^-?\d{1,3}(\.\d{3})+(,\d+)?$/', $s)) {
            $s = str_replace(['.', ','], ['', '.'], $s);
        } elseif (preg_match('/^-?\d+,\d+$/', $s)) {
            $s = str_replace(',', '.', $s);
        }

        return is_numeric($s) ? (float) $s : null;
    }

    /** Compare amounts at cent precision — the same figure restored verbatim under a second key is one figure. */
    private function cents(float $amount): int
    {
        return (int) round($amount * 100);
    }

    /**
     * Build the salary-table citation: the salary `.xlsx` source document, with
     * `chunk_id = null` and no page (it is structured data, not a prose chunk).
     * Returns [] when the table has no linked source document (still answered;
     * nothing to persist as a citation row, which requires a document_id).
     *
     * @return list<array<string,mixed>>
     */
    private function salaryCitation(SalaryTable $table): array
    {
        if (! $table->source_document_id) {
            return [];
        }
        $doc = Document::with('convenio:id,name')->find($table->source_document_id);
        if (! $doc) {
            return [];
        }

        return [[
            'chunk_id' => null, // salary is structured data, never a vector chunk (ADR-0006)
            'document_id' => $doc->id,
            'document_uuid' => $doc->uuid,
            'document_title' => $doc->title,
            'authority_level' => $doc->authority_level,
            'page_from' => null,
            'page_to' => null,
            'page_number' => null,
            'snippet' => "Tabla salarial {$table->year}".($doc->convenio?->name ? ' — '.$doc->convenio->name : ''),
            'is_salary_table' => true,
        ]];
    }

    /**
     * @param  array<string,mixed>  $salary
     * @return array{outcome:string, answer:string, citations:list<array<string,mixed>>, escalation_reason:string, categories:list<array<string,mixed>>, salary:array<string,mixed>}
     */
    private function escalate(array $salary): array
    {
        $salary['outcome'] = 'escalate';

        return [
            'outcome' => self::OUTCOME_ESCALATE,
            'answer' => self::COVERAGE_GAP_MESSAGE,
            'citations' => [],
            'escalation_reason' => 'salary_coverage_gap',
            'categories' => [],
            'salary' => $salary,
        ];
    }

    /** Spanish money format: 1575.63 → "1.575,63 €". */
    private function money($v): string
    {
        return number_format((float) $v, 2, ',', '.').' €';
    }

    /** Hourly rate, up to 4 decimals: 12.6629 → "12,6629 €/hora". */
    private function moneyHour($v): string
    {
        return rtrim(rtrim(number_format((float) $v, 4, ',', '.'), '0'), ',').' €/hora';
    }
}
