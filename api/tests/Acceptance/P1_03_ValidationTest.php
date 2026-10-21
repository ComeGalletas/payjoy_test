<?php

declare(strict_types=1);

namespace FxInstallments\Tests\Acceptance;

use FxInstallments\Config\ConfigRepository;
use FxInstallments\Tests\Support\InMemoryCurrencyAllowList;
use FxInstallments\Validation\AmountParser;
use FxInstallments\Validation\AmountValidator;
use FxInstallments\Validation\CurrencyResolver;
use FxInstallments\Validation\CurrencyValidator;
use FxInstallments\Validation\InstallmentsValidator;
use FxInstallments\Validation\Outcome;
use FxInstallments\Validation\ValidationFailed;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * P1-03 acceptance (docs/PLAN.md §10), the table of inputs:
 * - `1e3`, `NaN`, `-5`, `0`, `200.505` rejected (the strict validator, as `/convert` uses it);
 * - `200,5` → `200.50`, `1.000,50` → `1000.50`, `1,000` → `1000`, `1,2,3` → unclear
 *   (`AmountParser`, as the bot uses it);
 * - `brazil`, `reais`, `R$` → `BRL`; `eur` → `EUR` with no country (`CurrencyResolver`);
 * - `61` installments rejected.
 *
 * Country: only `brazil` records `Brazil`. `reais` and `R$` are currency names, not countries,
 * so they record no country, following the owner rule (CONTEXT.md "currency-country map": a
 * country is recorded only when the user typed one). The PLAN line says "with country Brazil"
 * for all three; that discrepancy is reported for the lead to fix.
 *
 * Round 2 (owner decisions 2026-10-21 and the judge's wrong-number findings):
 * - the strict validator rejects untrimmed input (` 5`, `5 `);
 * - in the bot, `200.505` (single `.` with 3 digits), `200 3` (inner space not grouping
 *   thousands) and `5$5` (marker inside the number) are unclear; `1 000` → `1000`;
 * - ambiguous currency text (`peso`, `$`) is unclear;
 * - `03` installments → 3 in the bot.
 */
final class P1_03_ValidationTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function rejectedAmounts(): iterable
    {
        foreach (['1e3', 'NaN', '-5', '0', '200.505', ' 5', '5 '] as $raw) {
            yield var_export($raw, true) => [$raw];
        }
    }

    #[DataProvider('rejectedAmounts')]
    public function testStrictAmountValidatorRejects(string $raw): void
    {
        $validator = new AmountValidator(new ConfigRepository());

        try {
            $validator->validate($raw);
            self::fail("{$raw} should be rejected");
        } catch (ValidationFailed $error) {
            self::assertSame('VALIDATION_AMOUNT', $error->errorCode);
            self::assertNotSame('', $error->messageForUser());
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function parsedAmounts(): iterable
    {
        yield '200,5' => ['200,5', '200.50'];
        yield '1.000,50' => ['1.000,50', '1000.50'];
        yield '1,000' => ['1,000', '1000'];
        yield '1 000' => ['1 000', '1000'];
    }

    #[DataProvider('parsedAmounts')]
    public function testAmountParserReadsBothSeparators(string $text, string $canonical): void
    {
        self::assertSame($canonical, AmountParser::normalize($text));

        // And the result passes the same strict validator `/convert` uses.
        $reading = self::amountParser()->read($text);
        self::assertSame(Outcome::Ok, $reading->outcome);
        self::assertEquals((new AmountValidator(new ConfigRepository()))->validate($canonical), $reading->amount);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unclearAmounts(): iterable
    {
        foreach (['1,2,3', '200.505', '200 3', '5$5'] as $text) {
            yield $text => [$text];
        }
    }

    #[DataProvider('unclearAmounts')]
    public function testAmountParserFindsUnclearText(string $text): void
    {
        self::assertNull(AmountParser::normalize($text));

        $reading = self::amountParser()->read($text);
        self::assertSame(Outcome::Unclear, $reading->outcome);
        self::assertNull($reading->amount);
        self::assertNotNull($reading->error);
        self::assertSame('VALIDATION_AMOUNT', $reading->error->errorCode);
        self::assertSame('unclear', $reading->error->reason);
    }

    /**
     * @return iterable<string, array{string, string, string|null}>
     */
    public static function resolvedCurrencies(): iterable
    {
        yield 'brazil' => ['brazil', 'BRL', 'Brazil'];
        yield 'reais' => ['reais', 'BRL', null];
        yield 'R$' => ['R$', 'BRL', null];
        yield 'eur' => ['eur', 'EUR', null];
    }

    #[DataProvider('resolvedCurrencies')]
    public function testCurrencyResolver(string $text, string $code, ?string $country): void
    {
        $resolver = new CurrencyResolver(new CurrencyValidator(new InMemoryCurrencyAllowList()));

        $resolution = $resolver->resolve($text);

        self::assertSame(Outcome::Ok, $resolution->outcome);
        self::assertSame($code, $resolution->currency);
        self::assertSame($country, $resolution->country);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function ambiguousCurrencies(): iterable
    {
        yield 'peso' => ['peso'];
        yield '$' => ['$'];
    }

    #[DataProvider('ambiguousCurrencies')]
    public function testAmbiguousCurrencyTextIsUnclear(string $text): void
    {
        $resolver = new CurrencyResolver(new CurrencyValidator(new InMemoryCurrencyAllowList()));

        $resolution = $resolver->resolve($text);

        self::assertSame(Outcome::Unclear, $resolution->outcome);
        self::assertNull($resolution->currency);
        self::assertNotNull($resolution->error);
        self::assertSame('VALIDATION_CURRENCY', $resolution->error->errorCode);
        self::assertSame('ambiguous', $resolution->error->reason);
    }

    public function testLeadingZeroInstallmentsAreReadInTheBot(): void
    {
        $validator = new InstallmentsValidator(new ConfigRepository());

        self::assertSame(3, $validator->fromText('03'));
    }

    public function testSixtyOneInstallmentsAreRejected(): void
    {
        $validator = new InstallmentsValidator(new ConfigRepository());
        self::assertSame(60, $validator->fromText('60'));

        try {
            $validator->fromText('61');
            self::fail('61 installments should be rejected');
        } catch (ValidationFailed $error) {
            self::assertSame('VALIDATION_INSTALLMENTS', $error->errorCode);
        }
    }

    private static function amountParser(): AmountParser
    {
        return new AmountParser(new AmountValidator(new ConfigRepository()));
    }
}
