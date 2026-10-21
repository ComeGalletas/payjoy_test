<?php

declare(strict_types=1);

namespace FxInstallments\Tests\Unit\Convert;

use FxInstallments\Convert\UsdCents;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UsdCentsTest extends TestCase
{
    /**
     * @return iterable<string, array{string, int}>
     */
    public static function canonicalAmounts(): iterable
    {
        yield 'one cent' => ['0.01', 1];
        yield 'two decimals' => ['200.50', 20050];
        yield 'one decimal' => ['200.5', 20050];
        yield 'no decimals' => ['200', 20000];
        yield 'the largest canonical amount' => ['9999999.99', 999_999_999];
        yield 'the amount.max cap' => ['1000000', 100_000_000];
        yield 'a float-unfriendly value' => ['0.29', 29];
        yield 'another float-unfriendly value' => ['1.15', 115];
        yield 'one.oh-five' => ['1.05', 105];
        yield 'zero (range is the validator\'s job)' => ['0', 0];
        yield 'leading zeros' => ['0007.10', 710];
    }

    #[DataProvider('canonicalAmounts')]
    public function testCanonicalAmountsConvertExactly(string $amount, int $cents): void
    {
        self::assertSame($cents, UsdCents::fromCanonical($amount));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidAmounts(): iterable
    {
        foreach (['', '1e3', 'NaN', 'INF', '-5', '+5', ' 5', '5 ', '200.505', '200.', '.50', '1,000', '1.000,50',
            '10000000', '12345678.00', '0x1A', "1.00\n", "\n1.00", '１２', '200.5.0', '200.-5'] as $amount) {
            yield var_export($amount, true) => [$amount];
        }
    }

    #[DataProvider('invalidAmounts')]
    public function testAnythingElseIsRejected(string $amount): void
    {
        $this->expectException(InvalidArgumentException::class);
        UsdCents::fromCanonical($amount);
    }

    public function testNoFloatIsInvolved(): void
    {
        // Every canonical cent value from 0.00 to 9.99 converts exactly, including the ones a
        // float route (x * 100) gets wrong when truncated, such as 0.29, 0.57 and 1.15.
        for ($cents = 0; $cents < 1000; $cents++) {
            $amount = intdiv($cents, 100) . '.' . str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
            self::assertSame($cents, UsdCents::fromCanonical($amount), $amount);
        }
        self::assertSame(28, (int) (0.29 * 100), 'the float route would truncate 0.29 to 28 cents');
    }

    public function testCentsFormatBackToTheCanonicalString(): void
    {
        self::assertSame('0.01', UsdCents::toCanonical(1));
        self::assertSame('200.50', UsdCents::toCanonical(20050));
        self::assertSame('9999999.99', UsdCents::toCanonical(999_999_999));
        self::assertSame('0.00', UsdCents::toCanonical(0));
        self::assertSame(20050, UsdCents::fromCanonical(UsdCents::toCanonical(20050)));

        $this->expectException(InvalidArgumentException::class);
        UsdCents::toCanonical(-1);
    }
}
