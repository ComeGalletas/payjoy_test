<?php

declare(strict_types=1);

namespace FxInstallments\Tests\Unit\Validation;

use FxInstallments\Config\ConfigRepository;
use FxInstallments\Validation\AmountValidator;
use FxInstallments\Validation\ValidationFailed;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AmountValidatorTest extends TestCase
{
    /**
     * @return iterable<string, array{string, int, string}>
     */
    public static function validAmounts(): iterable
    {
        yield 'whole' => ['200', 20000, '200.00'];
        yield 'one decimal' => ['200.5', 20050, '200.50'];
        yield 'two decimals' => ['200.50', 20050, '200.50'];
        yield 'one cent' => ['0.01', 1, '0.01'];
        yield 'float-unfriendly' => ['0.29', 29, '0.29'];
        yield 'exactly amount.max' => ['1000000', 100_000_000, '1000000.00'];
        yield 'amount.max with decimals' => ['1000000.00', 100_000_000, '1000000.00'];
        yield 'leading zeros' => ['007.5', 750, '7.50'];
        yield 'seven leading zeros' => ['0000000.5', 50, '0.50'];
        yield 'zero integer part' => ['0.10', 10, '0.10'];
    }

    #[DataProvider('validAmounts')]
    public function testValidAmountsGiveCentsAndTheCanonicalString(string $raw, int $cents, string $canonical): void
    {
        $amount = self::validator()->validate($raw);

        self::assertSame($cents, $amount->cents);
        self::assertSame($canonical, $amount->canonical);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidAmounts(): iterable
    {
        $format = ValidationFailed::REASON_FORMAT;
        foreach (['', '1e3', '1E3', 'NaN', 'INF', '-5', '+5', ' 5', '5 ', "5\n", "\n5", '200.505', '200.', '.50',
            '1,000', '200,5', '1.000,50', '0x1A', '１２', "5\0", '12345678', '5.5.5', '$5', '5 USD'] as $raw) {
            yield 'format ' . var_export($raw, true) => [$raw, $format];
        }
        yield 'zero' => ['0', ValidationFailed::REASON_NOT_POSITIVE];
        yield 'zero with decimals' => ['0.00', ValidationFailed::REASON_NOT_POSITIVE];
        yield 'many zeros' => ['0000000.0', ValidationFailed::REASON_NOT_POSITIVE];
        yield 'one cent over amount.max' => ['1000000.01', ValidationFailed::REASON_OVER_MAX];
        yield 'largest 7-digit amount' => ['9999999.99', ValidationFailed::REASON_OVER_MAX];
    }

    #[DataProvider('invalidAmounts')]
    public function testInvalidAmountsFailWithValidationAmount(string $raw, string $reason): void
    {
        try {
            self::validator()->validate($raw);
            self::fail('expected ValidationFailed');
        } catch (ValidationFailed $error) {
            self::assertSame('VALIDATION_AMOUNT', $error->errorCode);
            self::assertSame($reason, $error->reason);
            self::assertSame('Please enter a valid total in USD, for example 200 or 200.50.', $error->messageForUser());
        }
    }

    public function testTheCapComesFromConfigAndIsComparedInCents(): void
    {
        $validator = new AmountValidator(new ConfigRepository(['amount.max' => 500]));

        self::assertSame(50_000, $validator->maxCents());
        self::assertSame(50_000, $validator->validate('500.00')->cents);

        $this->expectException(ValidationFailed::class);
        $validator->validate('500.01');
    }

    public function testTheErrorMessageNeverEchoesTheInput(): void
    {
        try {
            self::validator()->validate('<script>1e3</script>');
            self::fail('expected ValidationFailed');
        } catch (ValidationFailed $error) {
            self::assertStringNotContainsString('script', $error->getMessage());
            self::assertSame('VALIDATION_AMOUNT (format)', $error->getMessage());
        }
    }

    private static function validator(): AmountValidator
    {
        return new AmountValidator(new ConfigRepository());
    }
}
