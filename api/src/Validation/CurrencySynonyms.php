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
        'GBP' => [
            'pound sterling', 'pounds sterling', 'British pound', 'British pounds', 'libra esterlina',
            'libras esterlinas', '£',
        ],
    ];

    /** @var list<string> */
    public const AMBIGUOUS = [
        '$', 'dollar', 'dollars', 'dólar', 'dólares', 'peso', 'pesos', 'dinar', 'dinars', 'dinares',
        '¥', 'pound', 'pounds', 'libra', 'libras', 'franc', 'francs', 'franco', 'francos',
        'crown', 'crowns', 'corona', 'coronas', 'krone', 'kronor', 'kr', 'rupee', 'rupees', 'rupia',
        'rupias', 'rial', 'riyal', 'riyales', 'C$', 'Panama', 'Panamá',
    ];
}
