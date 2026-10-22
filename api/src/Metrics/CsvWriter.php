<?php

declare(strict_types=1);

namespace FxInstallments\Metrics;

/**
 * CSV text for reports (docs/PLAN.md §3 "Output safety", P1-08): RFC 4180 rows with CRLF line
 * ends, and a guard against formula injection.
 *
 * - **Formula guard:** a cell starting with `=`, `+`, `-`, `@`, a tab or a CR gets a leading
 *   `'`, so a spreadsheet shows it as text instead of running it (`=CMD()` becomes `'=CMD()`).
 *   It applies to every cell, numbers included (report numbers are never negative).
 * - **Quoting:** a cell holding `"`, `,`, CR or LF is quoted, with each `"` doubled. Other
 *   cells are written as they are.
 * - Cells are strings or ints; null is an empty cell. Nothing here formats numbers.
 */
final class CsvWriter
{
    public const LINE_END = "\r\n";

    /** The first characters that make a spreadsheet read a cell as a formula. */
    private const FORMULA_START = ['=', '+', '-', '@', "\t", "\r"];

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
        if ($text !== '' && in_array($text[0], self::FORMULA_START, true)) {
            $text = "'" . $text;
        }
        if (strpbrk($text, "\",\r\n") !== false) {
            $text = '"' . str_replace('"', '""', $text) . '"';
        }

        return $text;
    }
}
