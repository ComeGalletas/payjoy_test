<?php

declare(strict_types=1);

namespace FxInstallments\Validation;

use FxInstallments\Convert\CurrencyCountryMap;
use LogicException;

/**
 * The bot's currency reader (docs/PLAN.md §9): maps what the user typed to a currency code.
 *
 * The whole text is folded (`TextNormalizer`: case, accents, spaces) and matched exactly, in
 * this order:
 * 1. an ambiguous name or symbol (`$`, `peso`, `dólar`) → unclear, never a guess; so is an
 *    English word that is also a code (`try`, `all`; `CurrencySynonyms::CODE_WORDS`) unless
 *    it was typed in capitals (`TRY` → TRY);
 * 2. a 3-letter code on the allow-list (`eur`, `BRL`) → that code, no country;
 * 3. the synonym table (`reais`, `R$`, `euro`) → its code, no country;
 * 4. the currency-country map (`brazil`, `España`) → its code and the canonical country name;
 * 5. otherwise invalid: `not_allowed` for a 3-letter code off the allow-list, `unknown` for
 *    anything else, `format` for empty, oversized or non-UTF-8 text.
 *
 * Every mapped code also goes through `CurrencyValidator`, so a synonym never bypasses the
 * allow-list.
 */
final class CurrencyResolver
{
    /** @var array<string, array{string, string|null}>|null folded text => [code, country] */
    private static ?array $index = null;

    /** @var array<string, true>|null */
    private static ?array $ambiguous = null;

    public function __construct(private readonly CurrencyValidator $validator)
    {
    }

    public function resolve(string $text): CurrencyResolution
    {
        $folded = TextNormalizer::fold($text);
        if ($folded === null || $folded === '') {
            return CurrencyResolution::invalid(ValidationFailed::currency(ValidationFailed::REASON_FORMAT));
        }

        if (isset(self::ambiguous()[$folded])) {
            return CurrencyResolution::unclear();
        }
        if (in_array($folded, CurrencySynonyms::CODE_WORDS, true) && !self::typedInCapitals($text)) {
            return CurrencyResolution::unclear();
        }

        $codeError = null;
        if (preg_match('/^[a-z]{3}$/D', $folded) === 1) {
            try {
                return CurrencyResolution::resolved($this->validator->validate($folded), null);
            } catch (ValidationFailed $error) {
                // Maybe a 3-letter name such as `yen` or `sol`: try the tables first.
                $codeError = $error;
            }
        }

        $entry = self::index()[$folded] ?? null;
        if ($entry !== null) {
            [$code, $country] = $entry;
            try {
                return CurrencyResolution::resolved($this->validator->validate($code), $country);
            } catch (ValidationFailed $error) {
                return CurrencyResolution::invalid($error);
            }
        }

        return CurrencyResolution::invalid(
            $codeError ?? ValidationFailed::currency(ValidationFailed::REASON_UNKNOWN),
        );
    }

    /**
     * Every folded entry of the synonym table and the currency-country map.
     *
     * @return array<string, array{string, string|null}> folded text => [code, country or null]
     *
     * @throws LogicException when two entries fold to the same text, or an entry is also listed
     *                        as ambiguous (a data bug)
     */
    public static function index(): array
    {
        if (self::$index !== null) {
            return self::$index;
        }

        $index = [];
        $add = static function (string $name, string $code, ?string $country) use (&$index): void {
            $folded = self::foldEntry($name);
            if (isset($index[$folded]) || isset(self::ambiguous()[$folded])) {
                throw new LogicException("Currency name listed twice: {$name}");
            }
            $index[$folded] = [$code, $country];
        };

        foreach (CurrencySynonyms::BY_CURRENCY as $code => $names) {
            foreach ($names as $name) {
                $add($name, $code, null);
            }
        }
        foreach (CurrencyCountryMap::all() as $country => $row) {
            foreach ($row['names'] as $name) {
                $add($name, $row['currency'], $country);
            }
        }

        return self::$index = $index;
    }

    /**
     * @return array<string, true>
     */
    private static function ambiguous(): array
    {
        if (self::$ambiguous === null) {
            $ambiguous = [];
            foreach (CurrencySynonyms::AMBIGUOUS as $name) {
                $ambiguous[self::foldEntry($name)] = true;
            }
            self::$ambiguous = $ambiguous;
        }

        return self::$ambiguous;
    }

    /**
     * Whether the text is three capital ASCII letters, ignoring surrounding whitespace. Only
     * called on text fold() accepted, so it is valid UTF-8.
     */
    private static function typedInCapitals(string $text): bool
    {
        return preg_match('/^[\s\p{Z}]*[A-Z]{3}[\s\p{Z}]*$/Du', $text) === 1;
    }

    private static function foldEntry(string $name): string
    {
        $folded = TextNormalizer::fold($name);
        if ($folded === null || $folded === '') {
            throw new LogicException("Currency table entry can't be folded: {$name}");
        }

        return $folded;
    }
}
