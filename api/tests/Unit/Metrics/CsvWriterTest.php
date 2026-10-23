<?php

declare(strict_types=1);

namespace FxInstallments\Tests\Unit\Metrics;

use FxInstallments\Metrics\CsvWriter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * docs/PLAN.md §3: CSV cells starting with `=`, `+`, `-`, `@`, tab or CR get a leading `'`;
 * RFC 4180 quoting otherwise.
 */
final class CsvWriterTest extends TestCase
{
    /**
     * @return iterable<string, array{string|int|null, string}>
     */
    public static function cells(): iterable
    {
        yield 'plain text' => ['BRL', 'BRL'];
        yield 'an int' => [1045, '1045'];
        yield 'zero' => [0, '0'];
        yield 'null is empty' => [null, ''];
        yield 'empty string' => ['', ''];
        yield 'equals' => ['=CMD()', "'=CMD()"];
        yield 'plus' => ['+1+1', "'+1+1"];
        yield 'minus' => ['-2+3', "'-2+3"];
        yield 'at' => ['@SUM(A1)', "'@SUM(A1)"];
        yield 'tab' => ["\t=1", "'\t=1"];
        yield 'CR is guarded and quoted' => ["\r=1", "\"'\r=1\""];
        yield 'equals later is fine' => ['a=b', 'a=b'];
        yield 'comma is quoted' => ['1,045.82', '"1,045.82"'];
        yield 'quote is doubled' => ['say "hi"', '"say ""hi"""'];
        yield 'LF is quoted' => ["a\nb", "\"a\nb\""];
        yield 'formula with a comma' => ['=HYPERLINK("http://x","y")', "\"'=HYPERLINK(\"\"http://x\"\",\"\"y\"\")\""];
        yield 'negative int is guarded too' => [-5, "'-5"];
        yield 'unicode text' => ['México', 'México'];
        // Round 2: semicolon locales, and whitespace before a formula.
        yield 'semicolon is quoted' => ['a;b', '"a;b"'];
        yield 'a formula after a semicolon' => ["X;=cmd|' /C calc'!A0;", "\"X;'=cmd|' /C calc'!A0;\""];
        yield 'every segment is guarded' => ['=1;+2;ok;@3', "\"'=1;'+2;ok;'@3\""];
        yield 'space before a formula' => [' =CMD()', "' =CMD()"];
        yield 'spaces before an at' => ['  @x', "'  @x"];
        yield 'newline before a formula' => ["\n=1", "\"'\n=1\""];
        yield 'space before a formula after a semicolon' => ['a; -1', "\"a;' -1\""];
        yield 'plain spaces are fine' => [' a b', ' a b'];
        // L-04: a segment that starts with a quote, and non-ASCII spaces before a formula.
        yield 'a segment that starts with a quote' => ['X;"=cmd"', '"X;\'""=cmd"""'];
        yield 'a cell that starts with a quote' => ['"=1"', '"\'""=1"""'];
        yield 'a quote after a space' => [' "x', '"\' ""x"'];
        yield 'a quote later is fine' => ['a"b', '"a""b"'];
        yield 'no-break space before a formula' => ["\u{00A0}=CMD()", "'\u{00A0}=CMD()"];
        yield 'ideographic space before a formula' => ["\u{3000}+1", "'\u{3000}+1"];
        yield 'em space and a space before a formula' => ["\u{2003} -1", "'\u{2003} -1"];
        yield 'narrow no-break space after a semicolon' => ["a;\u{202F}@x", "\"a;'\u{202F}@x\""];
        yield 'zero-width space before a formula' => ["\u{200B}=1", "'\u{200B}=1"];
        yield 'byte order mark before a formula' => ["\u{FEFF}=1", "'\u{FEFF}=1"];
        yield 'line separator before a formula' => ["\u{2028}=1", "'\u{2028}=1"];
        yield 'a no-break space before text is fine' => ["\u{00A0}a", "\u{00A0}a"];
        // L-04 cleanup B: next-line in both branches. In UTF-8 `\s` under `/u` matches U+0085.
        yield 'next-line (U+0085) before a formula' => ["\u{0085}=1", "'\u{0085}=1"];
        yield 'next-line after a semicolon' => ["a;\u{0085}-1", "\"a;'\u{0085}-1\""];
        yield 'not UTF-8: a Latin-1 no-break space before a formula' => ["\xA0=1", "'\xA0=1"];
        yield 'not UTF-8: a Latin-1 next-line before a formula' => ["\x85=1", "'\x85=1"];
        yield 'not UTF-8: other bytes are fine' => ["\xFFa", "\xFFa"];
    }

    #[DataProvider('cells')]
    public function testCell(string|int|null $value, string $expected): void
    {
        self::assertSame($expected, CsvWriter::cell($value));
    }

    public function testRowsEndInCrlf(): void
    {
        self::assertSame(
            "section,key,value\r\ncurrency,'=CMD(),3\r\n",
            CsvWriter::write([['section', 'key', 'value'], ['currency', '=CMD()', 3]]),
        );
        self::assertSame('', CsvWriter::write([]));
    }

    public function testAParserReadsTheCellsBack(): void
    {
        $cells = ['=CMD()', 'a,b', 'say "hi"', "two\nlines", '', 'plain'];
        $parsed = str_getcsv(rtrim(CsvWriter::row($cells), "\r\n"), ',', '"', '');

        self::assertSame(["'=CMD()", 'a,b', 'say "hi"', "two\nlines", '', 'plain'], $parsed);
    }

    /**
     * L-04: split at `;` the way a semicolon-locale spreadsheet does, no segment of a guarded
     * cell starts with a quote, or with any space and then a formula character.
     */
    public function testNoSegmentOfAGuardedCellStartsLikeAFormula(): void
    {
        foreach (['X;"=cmd|\' /C calc\'!A0"', "a;\u{00A0}=1;\u{3000}@2", "\u{FEFF}+1;\"x\"", '=1;"=2";-3'] as $value) {
            $parsed = str_getcsv(CsvWriter::cell($value), ',', '"', '');
            self::assertCount(1, $parsed, 'one cell');
            foreach (explode(';', $parsed[0]) as $segment) {
                self::assertDoesNotMatchRegularExpression('/^[\s\p{Z}\x{200B}\x{FEFF}]*["=+\-@]/u', $segment, "a segment of {$value}");
            }
        }
    }
}
