<?php

namespace App\Support;

/**
 * Render plain resolution text into a CLEAN, single-column PDF (Sprint 4, Q-A
 * "A1": the published `internal_hr_ruling` becomes retrievable through the
 * EXISTING ingestion path — `/extract` + `chunks:embed` — with hr-ai untouched).
 *
 * The generated PDF is deliberately engineered so the 2a extraction heuristics
 * (de-spacing, furniture stripping, two-column detection) cannot mangle it, so
 * the embedded chunk text round-trips the agent's typed text (verified at
 * publish; if a clean PDF is ever mangled we fall back to A2 — an hr-ai inline-
 * text branch — see plan §5/Q-A):
 *
 *  - ONE column, left-aligned (no positive two-column evidence → single stream).
 *  - NO header/footer/page-number → nothing REPEATS across pages → the furniture
 *    stripper (repetition-in-margin-band) finds nothing to strip.
 *  - Standard Helvetica + WinAnsiEncoding → PyMuPDF reads proper Unicode for
 *    Spanish accents (á é í ó ú ñ ¿ ¡ €).
 *  - Widened word spacing (`Tw`) → the inter-word gap sits comfortably above the
 *    de-spacer's median-relative threshold, so words are never glued together.
 *  - Lines never end on a hyphen → the de-spacer's end-of-line de-hyphenation
 *    ("traba-\\njadores" → "trabajadores") can never alter the text.
 *
 * No external dependency (a minimal, deterministic PDF writer): the content is
 * trusted, short prose; pulling in a full PDF/HTML-render stack would be heavier
 * than this focused writer and add a network/build dependency.
 */
class RulingPdf
{
    private const FONT_SIZE = 11.0;

    private const LEADING = 15.0;

    private const PAGE_W = 612.0;   // US Letter

    private const PAGE_H = 792.0;

    private const MARGIN = 72.0;

    private const WORD_SPACING = 3.0; // Tw — widens inter-word gaps robustly

    /** Helvetica AFM advance widths (units / 1000 em) for ASCII 32–126. */
    private const WIDTHS = [
        32 => 278, 33 => 278, 34 => 355, 35 => 556, 36 => 556, 37 => 889, 38 => 667, 39 => 191,
        40 => 333, 41 => 333, 42 => 389, 43 => 584, 44 => 278, 45 => 333, 46 => 278, 47 => 278,
        48 => 556, 49 => 556, 50 => 556, 51 => 556, 52 => 556, 53 => 556, 54 => 556, 55 => 556,
        56 => 556, 57 => 556, 58 => 278, 59 => 278, 60 => 584, 61 => 584, 62 => 584, 63 => 556,
        64 => 1015, 65 => 667, 66 => 667, 67 => 722, 68 => 722, 69 => 667, 70 => 611, 71 => 778,
        72 => 722, 73 => 278, 74 => 500, 75 => 667, 76 => 556, 77 => 833, 78 => 722, 79 => 778,
        80 => 667, 81 => 778, 82 => 722, 83 => 667, 84 => 611, 85 => 722, 86 => 667, 87 => 944,
        88 => 667, 89 => 667, 90 => 611, 91 => 278, 92 => 278, 93 => 278, 94 => 469, 95 => 556,
        96 => 333, 97 => 556, 98 => 556, 99 => 500, 100 => 556, 101 => 556, 102 => 278, 103 => 556,
        104 => 556, 105 => 222, 106 => 222, 107 => 500, 108 => 222, 109 => 833, 110 => 556, 111 => 556,
        112 => 556, 113 => 556, 114 => 333, 115 => 500, 116 => 278, 117 => 556, 118 => 500, 119 => 722,
        120 => 500, 121 => 500, 122 => 500, 123 => 334, 124 => 260, 125 => 334, 126 => 584,
    ];

    private const DEFAULT_WIDTH = 556; // accented letters etc. — close enough for wrapping

    /**
     * Render the text to PDF bytes.
     */
    public static function render(string $text): string
    {
        return (new self)->build($text);
    }

    private function build(string $text): string
    {
        // WinAnsi (CP1252) bytes so Helvetica + /WinAnsiEncoding extracts proper
        // Spanish accents. Characters outside CP1252 (rare in HR Spanish) become
        // "?" — surfaced by the publish-time round-trip check, never silent.
        $win = (string) (@mb_convert_encoding($text, 'Windows-1252', 'UTF-8') ?: $text);

        $usableWidth = self::PAGE_W - 2 * self::MARGIN;
        $linesPerPage = (int) floor((self::PAGE_H - 2 * self::MARGIN) / self::LEADING);

        $lines = $this->wrap($win, $usableWidth);
        $pages = array_chunk($lines, max(1, $linesPerPage)) ?: [[]];

        return $this->assemble($pages);
    }

    /**
     * Wrap WinAnsi text to display lines that fit the usable width. Preserves
     * blank lines (paragraph separators) and NEVER ends a line on a hyphen
     * (pushes a trailing-hyphen token to the next line) so de-hyphenation can't
     * alter the text.
     *
     * @return list<string>
     */
    private function wrap(string $win, float $usableWidth): array
    {
        $out = [];
        // Normalise CR/CRLF to LF; split on explicit newlines into source lines.
        $sourceLines = preg_split('/\r\n|\r|\n/', $win) ?: [''];

        foreach ($sourceLines as $src) {
            if (trim($src) === '') {
                $out[] = ''; // preserve paragraph break

                continue;
            }
            $words = preg_split('/ +/', $src) ?: [$src];
            $line = '';
            foreach ($words as $word) {
                $candidate = $line === '' ? $word : $line.' '.$word;
                if ($this->width($candidate) <= $usableWidth || $line === '') {
                    $line = $candidate;
                } else {
                    $out[] = $line;
                    $line = $word;
                }
            }
            if ($line !== '') {
                $out[] = $line;
            }
        }

        return $this->avoidTrailingHyphen($out);
    }

    /**
     * Ensure no emitted line ends with a hyphen (which the de-spacer would join
     * to the next line, dropping the hyphen). Move a trailing-hyphen word to the
     * start of the following line; if it is the only word, prefer keeping it but
     * append a zero-width-safe space is not possible in WinAnsi, so we instead
     * accept a single-token hyphen line ONLY when there is no following line.
     *
     * @param  list<string>  $lines
     * @return list<string>
     */
    private function avoidTrailingHyphen(array $lines): array
    {
        $result = [];
        $count = count($lines);
        for ($i = 0; $i < $count; $i++) {
            $line = $lines[$i];
            if ($line !== '' && str_ends_with(rtrim($line), '-')) {
                $words = explode(' ', $line);
                $last = array_pop($words);
                $head = implode(' ', $words);
                if ($head !== '' && $i + 1 < $count) {
                    // Push the hyphen word down to the next non-empty line.
                    $result[] = $head;
                    $lines[$i + 1] = $lines[$i + 1] === '' ? $last : $last.' '.$lines[$i + 1];

                    continue;
                }
            }
            $result[] = $line;
        }

        return $result;
    }

    private function width(string $win): float
    {
        $total = 0;
        $len = strlen($win);
        for ($i = 0; $i < $len; $i++) {
            $code = ord($win[$i]);
            $total += self::WIDTHS[$code] ?? self::DEFAULT_WIDTH;
        }

        return $total / 1000.0 * self::FONT_SIZE;
    }

    /**
     * Assemble the final PDF: catalog, pages, a shared font, and one
     * page+contents pair per page, with a correct xref table and trailer.
     *
     * @param  list<list<string>>  $pages
     */
    private function assemble(array $pages): string
    {
        $objects = [];   // 1-indexed object bodies (without the "N 0 obj" wrapper)
        $fontObj = 3;    // catalog=1, pages=2, font=3, then pages start at 4
        $pageObjIds = [];
        $contentObjIds = [];

        $nextId = 4;
        foreach ($pages as $lines) {
            $contentId = $nextId++;
            $pageId = $nextId++;
            $contentObjIds[] = $contentId;
            $pageObjIds[] = $pageId;
            $objects[$contentId] = $this->contentObject($lines);
            $objects[$pageId] = $this->pageObject($contentId, $fontObj);
        }

        $kids = implode(' ', array_map(fn ($id) => "$id 0 R", $pageObjIds));
        $count = count($pageObjIds);

        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[2] = "<< /Type /Pages /Kids [$kids] /Count $count >>";
        $objects[$fontObj] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';

        ksort($objects);

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($pdf);
            $pdf .= "$id 0 obj\n$body\nendobj\n";
        }

        $xrefPos = strlen($pdf);
        $total = count($objects) + 1; // +1 for the free object 0
        $pdf .= "xref\n0 $total\n";
        $pdf .= "0000000000 65535 f \n";
        for ($id = 1; $id < $total; $id++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$id] ?? 0);
        }
        $pdf .= "trailer\n<< /Size $total /Root 1 0 R >>\nstartxref\n$xrefPos\n%%EOF";

        return $pdf;
    }

    /** A page object referencing its content stream + the shared font. */
    private function pageObject(int $contentId, int $fontObj): string
    {
        $w = self::PAGE_W;
        $h = self::PAGE_H;

        return "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 $w $h] "
            ."/Resources << /Font << /F1 $fontObj 0 R >> >> /Contents $contentId 0 R >>";
    }

    /**
     * A content stream that draws the lines top-to-bottom with widened word
     * spacing. Blank lines advance one line (paragraph break).
     *
     * @param  list<string>  $lines
     */
    private function contentObject(array $lines): string
    {
        $size = self::FONT_SIZE;
        $lead = self::LEADING;
        $tw = self::WORD_SPACING;
        $startX = self::MARGIN;
        $startY = self::PAGE_H - self::MARGIN;

        $stream = "BT\n/F1 $size Tf\n$lead TL\n$tw Tw\n$startX $startY Td\n";
        foreach ($lines as $line) {
            if ($line === '') {
                $stream .= "T*\n";

                continue;
            }
            $stream .= '('.$this->escape($line).") Tj T*\n";
        }
        $stream .= 'ET';

        $len = strlen($stream);

        return "<< /Length $len >>\nstream\n$stream\nendstream";
    }

    /** Escape a WinAnsi byte string for a PDF literal string. */
    private function escape(string $win): string
    {
        return strtr($win, [
            '\\' => '\\\\',
            '(' => '\\(',
            ')' => '\\)',
            "\r" => '',
            "\n" => '',
        ]);
    }
}
