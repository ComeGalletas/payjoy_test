<?php

declare(strict_types=1);

namespace FxInstallments\Tests\Unit\Validation;

use FxInstallments\Validation\EdgeWhitespace;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EdgeWhitespaceTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function texts(): iterable
    {
        yield 'nothing to trim' => ['200', '200'];
        yield 'spaces' => ['  200  ', '200'];
        yield 'tab, CR, LF' => ["\t\r\n200\r\n", '200'];
        yield 'no-break spaces' => ["\u{00A0}200\u{202F}", '200'];
        yield 'mixed and repeated' => ["\u{00A0} \t\u{202F}200 \u{00A0}\n", '200'];
        yield 'inner whitespace kept' => ["\t1 \t000\n", "1 \t000"];
        yield 'only whitespace' => [" \t\u{00A0}", ''];
        yield 'empty' => ['', ''];
        yield 'vertical tab and NUL are not trimmed' => ["\v200\0", "\v200\0"];
        yield 'invalid UTF-8 is left alone' => ["\xC2200\xC2", "\xC2200\xC2"];
    }

    #[DataProvider('texts')]
    public function testTrim(string $text, string $expected): void
    {
        self::assertSame($expected, EdgeWhitespace::trim($text));
    }
}
