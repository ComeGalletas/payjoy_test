<?php

declare(strict_types=1);

namespace FxInstallments\Tests\Unit\Validation;

use FxInstallments\Convert\CurrencyCountryMap;
use FxInstallments\Tests\Support\InMemoryCurrencyAllowList;
use FxInstallments\Validation\CurrencyResolver;
use FxInstallments\Validation\CurrencySynonyms;
use FxInstallments\Validation\CurrencyValidator;
use FxInstallments\Validation\Outcome;
use FxInstallments\Validation\TextNormalizer;
use FxInstallments\Validation\ValidationFailed;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CurrencyResolverTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, string|null}>
     */
    public static function resolvedTexts(): iterable
    {
        // Codes, any case: no country.
        yield 'code' => ['BRL', 'BRL', null];
        yield 'lowercase code' => ['eur', 'EUR', null];
        yield 'code with spaces' => ['  cop ', 'COP', null];
        yield 'USD' => ['usd', 'USD', null];
        yield 'JPY' => ['JPY', 'JPY', null];
        yield 'KWD' => ['kwd', 'KWD', null];
        yield 'CLF' => ['Clf', 'CLF', null];

        // Synonym table: names and symbols, English and Spanish, no country.
        yield 'reais' => ['reais', 'BRL', null];
        yield 'real' => ['Real', 'BRL', null];
        yield 'R$' => ['R$', 'BRL', null];
        yield 'lowercase r$' => ['r$', 'BRL', null];
        yield 'real brasileño' => ['REAL BRASILEÑO', 'BRL', null];
        yield 'peso colombiano' => ['peso colombiano', 'COP', null];
        yield 'pesos colombianos, extra spaces' => ['  pesos   colombianos ', 'COP', null];
        yield 'Mexican peso' => ['mexican peso', 'MXN', null];
        yield 'euro' => ['euro', 'EUR', null];
        yield 'euros' => ['Euros', 'EUR', null];
        yield 'euro sign' => ['€', 'EUR', null];
        yield 'yen' => ['yen', 'JPY', null];
        yield 'yen japonés without accent' => ['yen japones', 'JPY', null];
        yield 'US dollar' => ['US Dollar', 'USD', null];
        yield 'dólar estadounidense' => ['dólar estadounidense', 'USD', null];
        yield 'dolar estadounidense without accent' => ['dolar estadounidense', 'USD', null];
        yield 'US$' => ['US$', 'USD', null];
        yield 'Kuwaiti dinar' => ['kuwaiti dinar', 'KWD', null];
        yield 'unidad de fomento' => ['Unidad de Fomento', 'CLF', null];
        yield 'UF' => ['UF', 'CLF', null];
        yield 'sol (3-letter name, not a code)' => ['sol', 'PEN', null];

        // Currency-country map: code plus the canonical English country name.
        yield 'brazil' => ['brazil', 'BRL', 'Brazil'];
        yield 'Brasil' => ['Brasil', 'BRL', 'Brazil'];
        yield 'BRAZIL' => ['BRAZIL', 'BRL', 'Brazil'];
        yield 'Colombia' => ['colombia', 'COP', 'Colombia'];
        yield 'Mexico' => ['Mexico', 'MXN', 'Mexico'];
        yield 'México' => ['México', 'MXN', 'Mexico'];
        yield 'MÉXICO' => ['MÉXICO', 'MXN', 'Mexico'];
        yield 'Spain' => ['spain', 'EUR', 'Spain'];
        yield 'España' => ['España', 'EUR', 'Spain'];
        yield 'espana without tilde' => ['espana', 'EUR', 'Spain'];
        yield 'Alemania' => ['alemania', 'EUR', 'Germany'];
        yield 'Países Bajos' => ['países bajos', 'EUR', 'Netherlands'];
        yield 'Estados Unidos' => ['Estados Unidos', 'USD', 'United States'];
        yield 'USA (3 letters, not a code)' => ['usa', 'USD', 'United States'];
        yield 'EE.UU.' => ['EE.UU.', 'USD', 'United States'];
        yield 'Ecuador' => ['ecuador', 'USD', 'Ecuador'];
        yield 'Japón' => ['Japón', 'JPY', 'Japan'];
        yield 'Kuwait' => ['Kuwait', 'KWD', 'Kuwait'];
        yield 'Chile' => ['chile', 'CLP', 'Chile'];
        yield 'Perú' => ['Perú', 'PEN', 'Peru'];
        yield 'Reino Unido' => ['reino unido', 'GBP', 'United Kingdom'];
        yield 'no-break spaces' => ["\u{00A0}Reino\u{00A0}Unido\u{00A0}", 'GBP', 'United Kingdom'];

        // P1-06 (P1-03 review): more countries and names.
        yield 'pound sign is sterling' => ['£', 'GBP', null];
        yield 'yuan' => ['yuan', 'CNY', null];
        yield 'renminbi' => ['Renminbi', 'CNY', null];
        yield 'China' => ['china', 'CNY', 'China'];
        yield 'Uruguay' => ['Uruguay', 'UYU', 'Uruguay'];
        yield 'peso uruguayo' => ['peso uruguayo', 'UYU', null];
        yield 'Bolivia' => ['bolivia', 'BOB', 'Bolivia'];
        yield 'bolivianos' => ['Bolivianos', 'BOB', null];
        yield 'Paraguay' => ['Paraguay', 'PYG', 'Paraguay'];
        yield 'guaraníes' => ['guaraníes', 'PYG', null];
        yield 'Venezuela' => ['Venezuela', 'VES', 'Venezuela'];
        yield 'bolívares' => ['bolivares', 'VES', null];
        yield 'Guatemala' => ['Guatemala', 'GTQ', 'Guatemala'];
        yield 'quetzales' => ['quetzales', 'GTQ', null];
        yield 'Honduras' => ['honduras', 'HNL', 'Honduras'];
        yield 'Nicaragua' => ['Nicaragua', 'NIO', 'Nicaragua'];
        yield 'Costa Rica' => ['costa  rica', 'CRC', 'Costa Rica'];
        yield 'colones' => ['colones', 'CRC', null];
        yield 'Belice' => ['Belice', 'BZD', 'Belize'];
        yield 'República Dominicana' => ['republica dominicana', 'DOP', 'Dominican Republic'];
        yield 'RD$' => ['RD$', 'DOP', null];
        yield 'Puerto Rico' => ['Puerto Rico', 'USD', 'Puerto Rico'];
        yield 'Canadá' => ['Canadá', 'CAD', 'Canada'];
        yield 'Canadian dollars' => ['canadian dollars', 'CAD', null];
    }

    #[DataProvider('resolvedTexts')]
    public function testResolvesCodesSynonymsAndCountries(string $text, string $code, ?string $country): void
    {
        $resolution = self::resolver()->resolve($text);

        self::assertSame(Outcome::Ok, $resolution->outcome);
        self::assertSame($code, $resolution->currency);
        self::assertSame($country, $resolution->country);
        self::assertNull($resolution->error);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function ambiguousTexts(): iterable
    {
        foreach (['$', 'peso', 'Pesos', 'dollar', 'dollars', 'dólar', 'DÓLAR', 'dolar', 'dólares', 'dinar', '¥', 'pound',
            'libra', 'franc', 'corona', 'kr', 'rupee', 'rial', 'C$', 'Panamá', 'panama',
            // P1-06 (P1-03 review): widened list.
            'won', 'dirham', 'Dírham', 'shilling', 'chelín', 'lira', 'Liras', 'krona', 'rials', 'riyals', 'leu',
            'lei', 'manat', 'som'] as $text) {
            yield $text => [$text];
        }
    }

    #[DataProvider('ambiguousTexts')]
    public function testAmbiguousTextIsUnclearNeverAGuess(string $text): void
    {
        $resolution = self::resolver()->resolve($text);

        self::assertSame(Outcome::Unclear, $resolution->outcome);
        self::assertNull($resolution->currency);
        self::assertNull($resolution->country);
        self::assertNotNull($resolution->error);
        self::assertSame('VALIDATION_CURRENCY', $resolution->error->errorCode);
        self::assertSame(ValidationFailed::REASON_AMBIGUOUS, $resolution->error->reason);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidTexts(): iterable
    {
        yield 'empty' => ['', ValidationFailed::REASON_FORMAT];
        yield 'only spaces' => ['   ', ValidationFailed::REASON_FORMAT];
        yield 'NUL byte' => ["BRL\0", ValidationFailed::REASON_FORMAT];
        yield 'invalid UTF-8' => ["Bras\xC3il", ValidationFailed::REASON_FORMAT];
        yield 'very long' => [str_repeat('brazil', 1000), ValidationFailed::REASON_FORMAT];
        yield 'code off the allow-list' => ['XYZ', ValidationFailed::REASON_NOT_ALLOWED];
        yield 'unknown name' => ['bitcoin', ValidationFailed::REASON_UNKNOWN];
        yield 'unknown country' => ['Atlantis', ValidationFailed::REASON_UNKNOWN];
        yield 'partial match' => ['I live in Brazil', ValidationFailed::REASON_UNKNOWN];
        yield 'four letters' => ['BRLX', ValidationFailed::REASON_UNKNOWN];
        // Also place names, so not listed bare (CurrencySynonyms, P1-06).
        yield 'córdoba' => ['Córdoba', ValidationFailed::REASON_UNKNOWN];
        yield 'colón' => ['colón', ValidationFailed::REASON_UNKNOWN];
        yield 'bolívar' => ['Bolívar', ValidationFailed::REASON_UNKNOWN];
    }

    #[DataProvider('invalidTexts')]
    public function testUnknownTextIsInvalid(string $text, string $reason): void
    {
        $resolution = self::resolver()->resolve($text);

        self::assertSame(Outcome::Invalid, $resolution->outcome);
        self::assertNull($resolution->currency);
        self::assertNotNull($resolution->error);
        self::assertSame('VALIDATION_CURRENCY', $resolution->error->errorCode);
        self::assertSame($reason, $resolution->error->reason);
    }

    public function testMappedCodesStillGoThroughTheAllowList(): void
    {
        $resolver = new CurrencyResolver(new CurrencyValidator(new InMemoryCurrencyAllowList(['USD'])));

        $resolution = $resolver->resolve('Brazil');
        self::assertSame(Outcome::Invalid, $resolution->outcome);
        self::assertNotNull($resolution->error);
        self::assertSame(ValidationFailed::REASON_NOT_ALLOWED, $resolution->error->reason);

        $resolution = $resolver->resolve('reais');
        self::assertSame(Outcome::Invalid, $resolution->outcome);

        self::assertSame('USD', $resolver->resolve('Ecuador')->currency);
    }

    public function testEveryTableEntryResolvesToItsOwnCurrency(): void
    {
        $resolver = self::resolver();
        $checked = 0;

        foreach (CurrencySynonyms::BY_CURRENCY as $code => $names) {
            foreach ($names as $name) {
                $resolution = $resolver->resolve($name);
                self::assertSame($code, $resolution->currency, $name);
                self::assertNull($resolution->country, $name);
                $checked++;
            }
        }
        foreach (CurrencyCountryMap::all() as $country => $row) {
            foreach ($row['names'] as $name) {
                $resolution = $resolver->resolve($name);
                self::assertSame($row['currency'], $resolution->currency, $name);
                self::assertSame($country, $resolution->country, $name);
                $checked++;
            }
        }

        self::assertSame(count(CurrencyResolver::index()), $checked, 'every entry folds to a distinct key');
    }

    public function testEveryMappedCodeIsOnTheTestAllowList(): void
    {
        $codes = array_keys(CurrencySynonyms::BY_CURRENCY);
        foreach (CurrencyCountryMap::all() as $row) {
            $codes[] = $row['currency'];
        }
        $codes = array_values(array_unique($codes));
        sort($codes);

        self::assertSame(InMemoryCurrencyAllowList::COMMON, $codes);
    }

    public function testAmbiguousEntriesAreNotInTheTables(): void
    {
        $index = CurrencyResolver::index();
        foreach (CurrencySynonyms::AMBIGUOUS as $name) {
            $folded = TextNormalizer::fold($name);
            self::assertNotNull($folded);
            self::assertArrayNotHasKey($folded, $index, $name);
        }
    }

    public function testNoTableEntryShadowsAnAllowedCode(): void
    {
        // Codes are checked before the tables, so a 3-letter entry equal to an allowed code
        // would never be reached.
        $allowList = new InMemoryCurrencyAllowList();
        foreach (array_keys(CurrencyResolver::index()) as $folded) {
            self::assertFalse($allowList->contains(strtoupper($folded)), $folded);
        }
    }

    private static function resolver(): CurrencyResolver
    {
        return new CurrencyResolver(new CurrencyValidator(new InMemoryCurrencyAllowList()));
    }
}
