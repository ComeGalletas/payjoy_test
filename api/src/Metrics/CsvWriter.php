<?php

declare(strict_types=1);

namespace FxInstallments\Metrics;

/**
 * CSV text for reports (docs/PLAN.md §3 "Output safety", P1-08): RFC 4180 rows with CRLF line
 * ends, and a guard against formula injection that holds on its own, whatever the labels are.
 *
 * - **Formula guard:** the cell, and each `;`-separated segment of it, that starts with `=`,
 *   `+`, `-`, `@`, a tab or a CR, or with whitespace followed by one of `= + - @` (` =CMD()`),
 *   gets a leading `'`, so a spreadsheet shows it as text instead of running it (`=CMD()`
 *   becomes `'=CMD()`). Segments matter because a spreadsheet opened in a semicolon locale
 *   (es-CO Excel) splits `X;=cmd|' /C calc'!A0` at the `;` (round 2). It applies to every
 *   cell, numbers included (report numbers are never negative).
 * - **Also guarded (L-04):** a segment that starts with `"` (a split segment such as `"=cmd"`
 *   can be read as a quoted field holding a formula), and whitespace that isn't ASCII before
 *   `= + - @`: any Unicode space or separator, a zero-width space or a byte order mark
 *   (` =1`). Text that isn't UTF-8 is checked byte-wise, with Latin-1's no-break space (0xA0)
 *   and next-line (0x85) counted as spaces. Both branches cover next-line: in UTF-8, `\s`
 *   under `/u` matches U+0085 (cleanup B). Reports can't produce any of these; the writer is
 *   safe on its own.
 * - **Quoting:** a cell holding `"`, `,`, `;`, CR or LF is quoted, with each `"` doubled, so
 *   neither separator splits it. Other cells are written as they are.
 * - Cells are strings or ints; null is an empty cell. Nothing here formats numbers.
 */
final class CsvWriter
{
    public const LINE_END = "\r\n";

    /**
     * @param list<list<string|int|null>> $rows
     */
    public static function write(array $rows): string
    {
        $text = '';
        foreach ($rows as $row) {
            $text .= self::row($row);
        }

        return $text;
    }

    /**
     * One line, with its CRLF.
     *
     * @param list<string|int|null> $cells
     */
    public static function row(array $cells): string
    {
        return implode(',', array_map(self::cell(...), $cells)) . self::LINE_END;
    }

    /**
     * One cell, guarded and quoted as needed.
     */
    public static function cell(string|int|null $value): string
    {
        $text = $value === null ? '' : (string) $value;
        $segments = explode(';', $text);
        foreach ($segments as $index => $segment) {
            if (self::looksLikeAFormula($segment)) {
                $segments[$index] = "'" . $segment;
            }
        }
        $text = implode(';', $segments);
        if (strpbrk($text, "\",;\r\n") !== false) {
            $text = '"' . str_replace('"', '""', $text) . '"';
        }

        return $text;
    }

    private static function looksLikeAFormula(string $text): bool
    {
        if ($text === '') {
            return false;
        }
        if ($text[0] === "\t" || $text[0] === "\r" || $text[0] === '"') {
            return true;
        }
        $unicode = preg_match('/^[\s\p{Z}\x{200B}\x{FEFF}]*["=+\-@]/u', $text);
        if ($unicode !== false) {
            return $unicode === 1;
        }

        // Not UTF-8: byte-wise, with Latin-1's no-break space and next-line as spaces.
        return preg_match('/^[\s\xA0\x85]*["=+\-@]/', $text) === 1;
    }
}
