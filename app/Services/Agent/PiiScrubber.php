<?php

namespace App\Services\Agent;

use App\Models\Employee;

/**
 * Sprint 13, build step 9 (plan.md §B.6.2) — scrubs an employee's question
 * BEFORE it is ever sent to the `general_knowledge` lane (hr-ai, and from
 * there, potentially a public web page fetch). hr-ai re-applies the
 * pattern-level part as defence in depth and REFUSES the call outright if
 * any pattern is still present (`app/general_lane.py::refuse_if_pii`) — this
 * class is the PRIMARY scrub, and the only one that knows who the employee
 * IS (name, convenio, territory), which a pattern alone cannot know.
 *
 * Every replacement is a TYPED placeholder (`[NOMBRE]`, `[EMAIL]`, `[DNI]`,
 * ...) rather than a blank — this keeps the scrubbed question grammatically
 * readable for the lane's own model call while guaranteeing no PII token
 * survives verbatim. Only the SCRUBBED text ever leaves this class's
 * boundary; scope/convenio/window/employee id are never passed to the lane
 * at all (§B.6.2 — a stronger posture than scrubbing alone).
 */
final class PiiScrubber
{
    /**
     * @return array{text:string,kinds:list<string>,count:int}
     */
    public function scrub(Employee $employee, string $question): array
    {
        $text = $question;
        $kinds = [];
        $count = 0;

        $hit = static function (string &$text, string $pattern, string $placeholder, string $kind, array &$kinds, int &$count): void {
            $n = 0;
            $text = preg_replace($pattern, $placeholder, $text, -1, $n) ?? $text;
            if ($n > 0) {
                $kinds[] = $kind;
                $count += $n;
            }
        };

        // 1. The employee's OWN full-name tokens (each word ≥3 chars, so a
        // stray "de"/"la" in a compound surname isn't scrubbed on its own —
        // it will still be caught as part of the adjacent real token).
        foreach ($this->nameTokens($employee->full_name ?? '') as $token) {
            $hit($text, '/\b'.self::quote($token).'\b/iu', '[NOMBRE]', 'name', $kinds, $count);
        }

        // 2. Email.
        $hit($text, '/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/u', '[EMAIL]', 'email', $kinds, $count);

        // 3. DNI: 8 digits + letter.
        $hit($text, '/\b\d{8}[A-Za-z]\b/u', '[DNI]', 'dni', $kinds, $count);

        // 4. NIE: X/Y/Z + 7 digits + letter.
        $hit($text, '/\b[XYZxyz]\d{7}[A-Za-z]\b/u', '[NIE]', 'nie', $kinds, $count);

        // 5. NAF / Número de Seguridad Social.
        $hit($text, '/\b\d{2}[\/ ]?\d{8}[\/ ]?\d{2}\b/u', '[NAF]', 'naf', $kinds, $count);

        // 6. IBAN (ES + 22 digits, optionally grouped in 4s).
        $hit($text, '/\bES\d{2}(\s?\d{4}){5}\b/iu', '[IBAN]', 'iban', $kinds, $count);

        // 7. Spanish mobile/landline phone.
        $hit($text, '/(\+34\s?)?[6789]\d{2}(\s?\d{3}){2}\b/u', '[TELEFONO]', 'phone', $kinds, $count);

        // 8. Convenio name/número/aliases.
        $convenio = $employee->convenio;
        if ($convenio !== null) {
            foreach (array_filter([$convenio->name, $convenio->numero, ...($convenio->aliases ?? [])]) as $needle) {
                $hit($text, '/'.self::quote((string) $needle).'/iu', '[CONVENIO]', 'convenio', $kinds, $count);
            }
        }

        // 9. Territory name/aliases.
        $territory = $employee->territory;
        if ($territory !== null) {
            foreach (array_filter([$territory->name, ...($territory->aliases ?? [])]) as $needle) {
                $hit($text, '/\b'.self::quote((string) $needle).'\b/iu', '[TERRITORIO]', 'territory', $kinds, $count);
            }
        }

        // 10. Money amounts (€ sign, "euros", or a decimal figure immediately
        // followed by one) — deliberately BEFORE the generic date/number
        // patterns below so "1.234,56 €" is tagged money, not a stray date.
        $hit($text, '/\d[\d.,]*\s*(?:€|euros?)(?!\w)/iu', '[CANTIDAD]', 'money', $kinds, $count);
        $hit($text, '/€\s*\d[\d.,]*/u', '[CANTIDAD]', 'money', $kinds, $count);

        // 11. Dates: dd/mm/yyyy, dd-mm-yyyy, yyyy-mm-dd, and "15 de enero
        // (de 2024)" prose form.
        $hit($text, '/\b\d{1,2}[\/\-]\d{1,2}[\/\-]\d{2,4}\b/u', '[FECHA]', 'date', $kinds, $count);
        $hit($text, '/\b\d{4}-\d{2}-\d{2}\b/u', '[FECHA]', 'date', $kinds, $count);
        $hit(
            $text,
            '/\b\d{1,2}\s+de\s+(enero|febrero|marzo|abril|mayo|junio|julio|agosto|septiembre|octubre|noviembre|diciembre)(\s+de\s+\d{4})?\b/iu',
            '[FECHA]',
            'date',
            $kinds,
            $count,
        );

        return [
            'text' => $text,
            'kinds' => array_values(array_unique($kinds)),
            'count' => $count,
        ];
    }

    /** @return list<string> */
    private function nameTokens(string $fullName): array
    {
        $tokens = preg_split('/\s+/u', trim($fullName)) ?: [];

        return array_values(array_filter($tokens, static fn (string $t): bool => mb_strlen($t) >= 3));
    }

    /**
     * `preg_quote()` already handles UTF-8 byte sequences safely (it
     * operates byte-wise but never splits a multi-byte sequence since none
     * of PCRE's meta-characters are valid UTF-8 continuation bytes) — this
     * wrapper exists only so every call site above reads the same way.
     */
    private static function quote(string $value): string
    {
        return preg_quote($value, '/');
    }
}
