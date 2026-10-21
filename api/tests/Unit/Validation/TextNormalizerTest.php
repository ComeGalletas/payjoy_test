<?php

declare(strict_types=1);

namespace FxInstallments\Tests\Unit\Validation;

use FxInstallments\Validation\TextNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TextNormalizerTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string|null}>
     */
    public static function texts(): iterable
    {
        yield 'lowercase' => ['Brazil', 'brazil'];
        yield 'uppercase accent' => ['MÉXICO', 'mexico'];
        yield 'tilde' => ['España', 'espana'];
        yield 'uppercase tilde' => ['ESPAÑA', 'espana'];
        yield 'acute on every vowel' => ['áéíóú', 'aeiou'];
        yield 'diaeresis and cedilla' => ['Ü ç', 'u c'];
        yield 'trim and collapse' => ["  pesos \t colombianos\n", 'pesos colombianos'];
        yield 'no-break spaces' => ["\u{00A0}euro\u{202F}", 'euro'];
        yield 'symbols untouched' => ['R$', 'r$'];
        yield 'euro sign untouched' => ['€', '€'];
        yield 'empty' => ['', ''];
        yield 'NUL byte' => ["euro\0", null];
        yield 'invalid UTF-8' => ["\xFF", null];
        yield 'at the length limit' => [str_repeat('a', TextNormalizer::MAX_INPUT_BYTES), str_repeat('a', TextNormalizer::MAX_INPUT_BYTES)];
        yield 'over the length limit' => [str_repeat('a', TextNormalizer::MAX_INPUT_BYTES + 1), null];
    }

    #[DataProvider('texts')]
    public function testFold(string $text, ?string $expected): void
    {
        self::assertSame($expected, TextNormalizer::fold($text));
    }

    public function testFoldingDoesNotDependOnTheLocale(): void
    {
        $previous = setlocale(LC_CTYPE, '0');
        try {
            // Turkish dotless-i rules would change `I` if case mapping were locale-aware. If the
            // host has no Turkish locale this falls back to C and still checks the result.
            setlocale(LC_CTYPE, 'tr_TR.UTF-8', 'tr_TR', 'Turkish_Turkey.1254', 'C');
            self::assertSame('irlanda', TextNormalizer::fold('IRLANDA'));
            self::assertSame('mexico', TextNormalizer::fold('MÉXICO'));
        } finally {
            setlocale(LC_CTYPE, $previous === false ? 'C' : $previous);
        }
    }
}
