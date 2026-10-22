<?php

declare(strict_types=1);

namespace FxInstallments\Validation;

/**
 * The synonym table (CONTEXT.md): currency names and symbols, in English and Spanish, that the
 * bot maps to a code. Country names live in the currency-country map
 * (`Convert/CurrencyCountryMap`). Data only; `CurrencyResolver` folds every entry with
 * `TextNormalizer` and matches the user's whole folded text, so case, accents and extra spaces
 * don't matter here.
 *
 * AMBIGUOUS lists text that names more than one currency. It is never guessed: the resolver
 * answers "unclear".
 */
final class CurrencySynonyms
{
    /** @var array<string, list<string>> code => names and symbols */
    public const BY_CURRENCY = [
        'BRL' => [
            'real', 'reais', 'reales', 'R$',
            'Brazilian real', 'Brazilian reais', 'real brasileño', 'reales brasileños', 'real brasileiro',
        ],
        'COP' => ['peso colombiano', 'pesos colombianos', 'Colombian peso', 'Colombian pesos', 'COL$'],
        'MXN' => ['peso mexicano', 'pesos mexicanos', 'Mexican peso', 'Mexican pesos', 'MX$', 'MEX$'],
        'EUR' => ['euro', 'euros', '€'],
        'USD' => [
            'US dollar', 'US dollars', 'U.S. dollar', 'U.S. dollars', 'United States dollar',
            'United States dollars', 'American dollar', 'American dollars', 'US$',
            'dólar estadounidense', 'dólares estadounidenses', 'dólar americano', 'dólares americanos',
        ],
        'JPY' => ['yen', 'yenes', 'Japanese yen', 'yen japonés', 'yenes japoneses'],
        'KWD' => [
            'Kuwaiti dinar', 'Kuwaiti dinars', 'dinar kuwaití', 'dinares kuwaitíes', 'dinar de Kuwait',
            'dinares de Kuwait',
        ],
        'CLF' => ['unidad de fomento', 'unidades de fomento', 'UF'],
        'CLP' => ['peso chileno', 'pesos chilenos', 'Chilean peso', 'Chilean pesos', 'CLP$'],
        'ARS' => ['peso argentino', 'pesos argentinos', 'Argentine peso', 'Argentine pesos', 'AR$'],
        'PEN' => ['sol', 'soles', 'sol peruano', 'soles peruanos', 'Peruvian sol', 'Peruvian soles', 'S/'],
        // `£` alone is sterling; the other pound currencies are written with a prefix (E£, L£,
        // £S), and `pound` alone stays ambiguous (P1-03 review).
        'GBP' => [
            'pound sterling', 'pounds sterling', 'British pound', 'British pounds', 'libra esterlina',
            'libras esterlinas', '£',
        ],
        // Added in P1-06 with the currency-country map's new countries (P1-03 review).
        // `¥` stays ambiguous (JPY and CNY).
        'CNY' => ['yuan', 'yuanes', 'renminbi', 'RMB', 'Chinese yuan', 'yuan chino', 'yuanes chinos'],
        'CAD' => ['Canadian dollar', 'Canadian dollars', 'dólar canadiense', 'dólares canadienses', 'CA$', 'CAN$'],
        'UYU' => ['peso uruguayo', 'pesos uruguayos', 'Uruguayan peso', 'Uruguayan pesos', '$U', 'UY$'],
        'BOB' => ['boliviano', 'bolivianos', 'Bolivian boliviano', 'Bolivian bolivianos'],
        'PYG' => ['guaraní', 'guaraníes', 'guaranis', 'Paraguayan guaraní', 'Paraguayan guaranís', '₲'],
        // Bare `bolívar`, `córdoba` and `colón` are also place names (a Colombian department, an
        // Argentine city, a Panamanian city), so only plurals and qualified names are listed.
        'VES' => ['bolívares', 'Venezuelan bolívar', 'Venezuelan bolívares', 'bolívar venezolano', 'bolívares venezolanos'],
        'GTQ' => ['quetzal', 'quetzales', 'Guatemalan quetzal', 'Guatemalan quetzales'],
        'HNL' => ['lempira', 'lempiras', 'Honduran lempira', 'Honduran lempiras'],
        'NIO' => ['córdobas', 'Nicaraguan córdoba', 'Nicaraguan córdobas', 'córdoba nicaragüense', 'córdobas nicaragüenses'],
        'CRC' => ['colones', 'Costa Rican colón', 'Costa Rican colones', 'colón costarricense', 'colones costarricenses', '₡'],
        'BZD' => ['Belize dollar', 'Belize dollars', 'dólar beliceño', 'dólares beliceños', 'BZ$'],
        'DOP' => ['peso dominicano', 'pesos dominicanos', 'Dominican peso', 'Dominican pesos', 'RD$'],
    ];

    /** @var list<string> */
    public const AMBIGUOUS = [
        '$', 'dollar', 'dollars', 'dólar', 'dólares', 'peso', 'pesos', 'dinar', 'dinars', 'dinares',
        '¥', 'pound', 'pounds', 'libra', 'libras', 'franc', 'francs', 'franco', 'francos',
        'crown', 'crowns', 'corona', 'coronas', 'krone', 'kronor', 'kr', 'rupee', 'rupees', 'rupia',
        'rupias', 'rial', 'riyal', 'riyales', 'C$', 'Panama', 'Panamá',
        // Widened in P1-06 (P1-03 review): each names several currencies.
        'won', 'wones', 'dirham', 'dirhams', 'dirhames', 'shilling', 'shillings', 'chelín', 'chelines',
        'lira', 'liras', 'lire', 'krona', 'kroner', 'rials', 'riales', 'riyals', 'leu', 'lei', 'manat',
        'manats', 'som', 'soms',
    ];

    /**
     * Common English words that are also currency codes, once the allow-list holds every code
     * (P1-04). Typed in capitals (`TRY`, ` ALL `) they are the code; typed any other way
     * (`try`, `Try`, which a phone keyboard capitalises) they are ambiguous currency text, so
     * a conversational reply is never read as a Turkish lira or an Albanian lek.
     *
     * Only words people plausibly type as a reply, and only codes the synonym table and the
     * currency-country map don't already serve: `pen` and `cop` stay codes (PEN and COP are
     * core currencies here, and `cop` → COP is P1-03 behaviour). `can` is not a code (CAD is).
     * A test fails if a mapped currency's code is listed here.
     *
     * P1-06: Bolivia → BOB is mapped, so `bob` left this list and is the code BOB in any case,
     * like `pen` and `cop` (a name, not a plausible reply to "which currency or country?").
     * No Spanish or Portuguese word needs the rule: the codes that are also such words (`sos`,
     * already here; `ves`, `ron`, `zar`, `gel`) aren't plausible one-word replies, and the
     * common replies (`si`, `sim`, `no`, `ok`, `ola`, `oi`, `dale`, `listo`) aren't codes.
     *
     * @var list<string>
     */
    public const CODE_WORDS = ['all', 'cup', 'kid', 'mad', 'mop', 'sos', 'top', 'try'];
}
