<?php

declare(strict_types=1);

namespace FxInstallments\Tests\Unit\Validation;

use FxInstallments\Tests\Support\InMemoryCurrencyAllowList;
use FxInstallments\Validation\CurrencyValidator;
use FxInstallments\Validation\ValidationFailed;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CurrencyValidatorTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function validCodes(): iterable
    {
        yield 'uppercase' => ['BRL', 'BRL'];
        yield 'lowercase' => ['brl', 'BRL'];
        yield 'mixed case' => ['eUr', 'EUR'];
        yield 'surrounding spaces' => ['  USD ', 'USD'];
        yield 'tab and newline' => ["\tJPY\n", 'JPY'];
        yield 'four minor units' => ['clf', 'CLF'];
    }

    #[DataProvider('validCodes')]
    public function testValidCodesAreTrimmedAndUppercased(string $raw, string $code): void
    {
        self::assertSame($code, self::validator()->validate($raw));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidCodes(): iterable
    {
        $format = ValidationFailed::REASON_FORMAT;
        foreach (['', 'US', 'USDT', 'U5D', 'U D', 'US-', '$', 'BR L', "USD\0", "\0USD", 'ÜSD', 'ＵＳＤ', 'Brazil',
            "USD\nEUR", '../USD', 'USD?x=1'] as $raw) {
            yield 'format ' . var_export($raw, true) => [$raw, $format];
        }
        yield 'well-formed, not on the allow-list' => ['XYZ', ValidationFailed::REASON_NOT_ALLOWED];
        yield 'real code, not on the allow-list' => ['VES', ValidationFailed::REASON_NOT_ALLOWED];
    }

    #[DataProvider('invalidCodes')]
    public function testInvalidCodesFailWithValidationCurrency(string $raw, string $reason): void
    {
        try {
            self::validator()->validate($raw);
            self::fail('expected ValidationFailed');
        } catch (ValidationFailed $error) {
            self::assertSame('VALIDATION_CURRENCY', $error->errorCode);
            self::assertSame($reason, $error->reason);
            self::assertSame('Please use a supported 3-letter currency code, for example BRL.', $error->messageForUser());
        }
    }

    public function testTheAllowListIsInjected(): void
    {
        $validator = new CurrencyValidator(new InMemoryCurrencyAllowList(['VES']));

        self::assertSame('VES', $validator->validate('ves'));

        $this->expectException(ValidationFailed::class);
        $validator->validate('USD');
    }

    private static function validator(): CurrencyValidator
    {
        return new CurrencyValidator(new InMemoryCurrencyAllowList());
    }
}
