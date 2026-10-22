<?php

declare(strict_types=1);

namespace FxInstallments\Tests\Unit\Validation;

use FxInstallments\Convert\CurrencyCountryMap;
use FxInstallments\Db\Connection;
use FxInstallments\Rates\FallbackCurrencies;
use FxInstallments\Rates\RateStore;
use FxInstallments\Rates\RateTable;
use FxInstallments\Rates\RateTableAllowList;
use FxInstallments\Tests\Support\TestEnvironment;
use FxInstallments\Validation\CurrencyResolver;
use FxInstallments\Validation\CurrencySynonyms;
use FxInstallments\Validation\CurrencyValidator;
use FxInstallments\Validation\Outcome;
use FxInstallments\Validation\TextNormalizer;
use FxInstallments\Validation\ValidationFailed;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * P1-04 "Also": once the allow-list holds every code (the real RateTableAllowList, here on its
 * fallback currency list and on a stored table), English words that are also codes are
 * ambiguous unless typed in capitals.
 */
final class CurrencyCodeWordsTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function wordsAnyCase(): iterable
    {
        foreach (CurrencySynonyms::CODE_WORDS as $word) {
            yield $word => [$word];
            yield ucfirst($word) => [ucfirst($word)];
            yield " {$word} " => [" {$word} "];
        }
        yield 'mixed case' => ['tRY'];
    }

    #[DataProvider('wordsAnyCase')]
    public function testAWordThatIsAlsoACodeIsAmbiguousUnlessInCapitals(string $text): void
    {
        foreach ([self::fallbackResolver(), self::storedTableResolver()] as $resolver) {
            $resolution = $resolver->resolve($text);

            self::assertSame(Outcome::Unclear, $resolution->outcome, $text);
            self::assertNotNull($resolution->error);
            self::assertSame(ValidationFailed::REASON_AMBIGUOUS, $resolution->error->reason);
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function capitals(): iterable
    {
        yield 'TRY' => ['TRY', 'TRY'];
        yield 'ALL' => ['ALL', 'ALL'];
        yield 'TOP' => ['TOP', 'TOP'];
        yield 'CUP' => ['CUP', 'CUP'];
        yield 'MOP' => ['MOP', 'MOP'];
        yield 'BOB' => ['BOB', 'BOB'];
        yield 'SOS' => ['SOS', 'SOS'];
        yield 'with spaces' => ['  TRY ', 'TRY'];
        yield 'with a no-break space' => ["\u{00A0}ALL", 'ALL'];
    }

    #[DataProvider('capitals')]
    public function testTheCapitalisedCodeIsTheCode(string $text, string $code): void
    {
        $resolution = self::fallbackResolver()->resolve($text);

        self::assertSame(Outcome::Ok, $resolution->outcome);
        self::assertSame($code, $resolution->currency);
        self::assertNull($resolution->country);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function lowercaseCodes(): iterable
    {
        yield 'pen (Peru, a mapped currency)' => ['pen', 'PEN'];
        yield 'cop (Colombia, P1-03 behaviour)' => ['cop', 'COP'];
        yield 'eur' => ['eur', 'EUR'];
        yield 'cad' => ['cad', 'CAD'];
        yield 'rub' => ['rub', 'RUB'];
        yield 'gel' => ['gel', 'GEL'];
        // P1-06: Bolivia → BOB is mapped, so `bob` is no longer a code word (CurrencySynonyms).
        yield 'bob (Bolivia, mapped in P1-06)' => ['bob', 'BOB'];
        yield 'Bob' => ['Bob', 'BOB'];
        // Spanish words that are also codes stay codes (P1-06 decision, CurrencySynonyms).
        yield 'ves (Venezuela, mapped in P1-06)' => ['ves', 'VES'];
        yield 'ron' => ['ron', 'RON'];
        yield 'zar' => ['zar', 'ZAR'];
    }

    public function testWonIsAmbiguousNotACode(): void
    {
        // P1-06 (P1-03 review): `won` names KRW and KPW, so it moved to the ambiguous list.
        $resolution = self::fallbackResolver()->resolve('won');

        self::assertSame(Outcome::Unclear, $resolution->outcome);
        self::assertNotNull($resolution->error);
        self::assertSame(ValidationFailed::REASON_AMBIGUOUS, $resolution->error->reason);
    }

    public function testNoAmbiguousEntryIsACodeOnTheRealList(): void
    {
        // Ambiguous text is checked before codes, so an ambiguous entry equal to a code would
        // hide that code in every case.
        foreach (CurrencySynonyms::AMBIGUOUS as $name) {
            $folded = (string) TextNormalizer::fold($name);
            self::assertFalse(FallbackCurrencies::contains(strtoupper($folded)), $name);
        }
    }

    #[DataProvider('lowercaseCodes')]
    public function testOtherCodesStillResolveInLowercase(string $text, string $code): void
    {
        self::assertSame($code, self::fallbackResolver()->resolve($text)->currency);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function notCodes(): iterable
    {
        yield 'can (the code is CAD)' => ['can'];
        yield 'yes' => ['yes'];
        yield 'XAU (a metal, not on the fallback list)' => ['XAU'];
    }

    #[DataProvider('notCodes')]
    public function testThreeLetterWordsThatAreNotCodesAreNotAllowed(string $text): void
    {
        $resolution = self::fallbackResolver()->resolve($text);

        self::assertSame(Outcome::Invalid, $resolution->outcome);
        self::assertNotNull($resolution->error);
        self::assertSame(ValidationFailed::REASON_NOT_ALLOWED, $resolution->error->reason);
    }

    public function testEveryCodeWordIsARealCodeThatNoTableNames(): void
    {
        $mapped = array_keys(CurrencySynonyms::BY_CURRENCY);
        foreach (CurrencyCountryMap::all() as $row) {
            $mapped[] = $row['currency'];
        }
        $index = CurrencyResolver::index();
        foreach (CurrencySynonyms::CODE_WORDS as $word) {
            self::assertMatchesRegularExpression('/^[a-z]{3}$/D', $word);
            self::assertTrue(FallbackCurrencies::contains(strtoupper($word)), "{$word} is a code on the fallback list");
            self::assertNotContains(strtoupper($word), $mapped, "{$word}: a mapped currency's code must stay a code");
            self::assertArrayNotHasKey($word, $index, "{$word} is not a table entry");
            self::assertNotContains($word, array_map(static fn (string $name): ?string => TextNormalizer::fold($name), CurrencySynonyms::AMBIGUOUS));
        }
        self::assertSame(array_values(array_unique(CurrencySynonyms::CODE_WORDS)), CurrencySynonyms::CODE_WORDS);
    }

    public function testNoTableEntryShadowsACodeOnTheRealAllowList(): void
    {
        // Codes are checked before the tables, so a 3-letter entry equal to an allowed code
        // would never be reached. This is the P1-03 shadowing test against the real list.
        foreach (array_keys(CurrencyResolver::index()) as $folded) {
            self::assertFalse(FallbackCurrencies::contains(strtoupper((string) $folded)), (string) $folded);
        }
    }

    public function testThreeLetterNamesStillResolveWithTheRealList(): void
    {
        $resolver = self::fallbackResolver();

        self::assertSame('PEN', $resolver->resolve('sol')->currency);
        self::assertSame('JPY', $resolver->resolve('yen')->currency);
        self::assertSame('USD', $resolver->resolve('usa')->currency);
        self::assertSame('United States', $resolver->resolve('usa')->country);
    }

    private static function fallbackResolver(): CurrencyResolver
    {
        $store = new RateStore(Connection::wrap(TestEnvironment::connect(TestEnvironment::freshDatabasePath())));
        $allowList = new RateTableAllowList($store);
        self::assertTrue($allowList->usesFallback());

        return new CurrencyResolver(new CurrencyValidator($allowList));
    }

    private static function storedTableResolver(): CurrencyResolver
    {
        $store = new RateStore(Connection::wrap(TestEnvironment::connect(TestEnvironment::freshDatabasePath())));
        $rates = array_fill_keys(array_map('strtoupper', CurrencySynonyms::CODE_WORDS), 10_000_000_000);
        $store->replace(new RateTable($rates + ['USD' => 10_000_000_000], 1, 2, 1));

        return new CurrencyResolver(new CurrencyValidator(new RateTableAllowList($store)));
    }
}
