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
}
