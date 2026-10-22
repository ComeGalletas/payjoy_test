<?php

declare(strict_types=1);

namespace FxInstallments\Convert;

/**
 * The currency-country map (CONTEXT.md, docs/PLAN.md §4–5): a PHP array, not a table. Each
 * country has its canonical English name (what `events.country` records), its currency and the
 * names users may type in English and Spanish. `Validation/CurrencyResolver` folds the names
 * (case, accents, spaces) and matches the user's whole text.
 *
 * Only countries whose sole legal tender is one currency are listed. Countries with two
 * (Panama: PAB and USD) are in `CurrencySynonyms::AMBIGUOUS` instead. Not exhaustive: add a
 * row here when a new country is needed.
 *
 * List each spelling once after folding: `Peru` already matches `Perú` and `PERU`, and the
 * resolver refuses two entries that fold to the same text.
 */
final class CurrencyCountryMap
{
    /**
     * canonical English name => [currency, names typed in English and Spanish]
     *
     * @var array<string, array{string, list<string>}>
     */
    private const COUNTRIES = [
        // BRL, COP, MXN (the §9 buttons, with EUR below)
        'Brazil' => ['BRL', ['Brazil', 'Brasil']],
        'Colombia' => ['COP', ['Colombia']],
        'Mexico' => ['MXN', ['Mexico', 'Méjico']],

        // USD
        'United States' => ['USD', [
            'United States', 'United States of America', 'USA', 'US', 'U.S.', 'U.S.A.',
            'Estados Unidos', 'Estados Unidos de América', 'EEUU', 'EE.UU.', 'EE. UU.',
        ]],
        'Ecuador' => ['USD', ['Ecuador']],
        'El Salvador' => ['USD', ['El Salvador']],
        'Timor-Leste' => ['USD', ['Timor-Leste', 'East Timor', 'Timor Oriental']],
        'Micronesia' => ['USD', ['Micronesia', 'Federated States of Micronesia', 'Estados Federados de Micronesia']],
        'Marshall Islands' => ['USD', ['Marshall Islands', 'Islas Marshall']],
        'Palau' => ['USD', ['Palau', 'Palaos']],

        // EUR: the euro area (Bulgaria joined on 2026-01-01) and the countries that use the euro
        // by agreement or unilaterally.
        'Austria' => ['EUR', ['Austria']],
        'Belgium' => ['EUR', ['Belgium', 'Bélgica']],
        'Bulgaria' => ['EUR', ['Bulgaria']],
        'Croatia' => ['EUR', ['Croatia', 'Croacia']],
        'Cyprus' => ['EUR', ['Cyprus', 'Chipre']],
        'Estonia' => ['EUR', ['Estonia']],
        'Finland' => ['EUR', ['Finland', 'Finlandia']],
        'France' => ['EUR', ['France', 'Francia']],
        'Germany' => ['EUR', ['Germany', 'Alemania']],
        'Greece' => ['EUR', ['Greece', 'Grecia']],
        'Ireland' => ['EUR', ['Ireland', 'Irlanda']],
        'Italy' => ['EUR', ['Italy', 'Italia']],
        'Latvia' => ['EUR', ['Latvia', 'Letonia']],
        'Lithuania' => ['EUR', ['Lithuania', 'Lituania']],
        'Luxembourg' => ['EUR', ['Luxembourg', 'Luxemburgo']],
        'Malta' => ['EUR', ['Malta']],
        'Netherlands' => ['EUR', ['Netherlands', 'The Netherlands', 'Holland', 'Países Bajos', 'Holanda']],
        'Portugal' => ['EUR', ['Portugal']],
        'Slovakia' => ['EUR', ['Slovakia', 'Eslovaquia']],
        'Slovenia' => ['EUR', ['Slovenia', 'Eslovenia']],
        'Spain' => ['EUR', ['Spain', 'España']],
        'Andorra' => ['EUR', ['Andorra']],
        'Kosovo' => ['EUR', ['Kosovo']],
        'Monaco' => ['EUR', ['Monaco']],
        'Montenegro' => ['EUR', ['Montenegro']],
        'San Marino' => ['EUR', ['San Marino']],
        'Vatican City' => ['EUR', ['Vatican City', 'Vatican', 'Holy See', 'Ciudad del Vaticano', 'Vaticano', 'Santa Sede']],

        // JPY, KWD. CLF (unidad de fomento) is a unit of account, not a country's currency, so it
        // is reached only through the synonym table; Chile maps to CLP.
        'Japan' => ['JPY', ['Japan', 'Japón']],
        'Kuwait' => ['KWD', ['Kuwait']],
        'Chile' => ['CLP', ['Chile']],

        // Others with entries in the synonym table.
        'Argentina' => ['ARS', ['Argentina']],
        'Peru' => ['PEN', ['Peru']],
        'United Kingdom' => ['GBP', [
            'United Kingdom', 'UK', 'U.K.', 'Great Britain', 'Britain', 'England', 'Scotland', 'Wales',
            'Northern Ireland', 'Reino Unido', 'Gran Bretaña', 'Inglaterra', 'Escocia', 'Gales',
            'Irlanda del Norte',
        ]],

        // P1-06 (P1-03 review): the rest of Latin America, the Dominican Republic, Puerto Rico,
        // Canada and China. Panama has two currencies and stays ambiguous. Cuba is left out:
        // mapping it to CUP would make `cup` a code in any case (CurrencyCodeWordsTest).
        'Uruguay' => ['UYU', ['Uruguay']],
        'Bolivia' => ['BOB', ['Bolivia']],
        'Paraguay' => ['PYG', ['Paraguay']],
        'Venezuela' => ['VES', ['Venezuela']],
        'Guatemala' => ['GTQ', ['Guatemala']],
        'Honduras' => ['HNL', ['Honduras']],
        'Nicaragua' => ['NIO', ['Nicaragua']],
        'Costa Rica' => ['CRC', ['Costa Rica']],
        'Belize' => ['BZD', ['Belize', 'Belice']],
        'Dominican Republic' => ['DOP', ['Dominican Republic', 'República Dominicana']],
        'Puerto Rico' => ['USD', ['Puerto Rico']],
        'Canada' => ['CAD', ['Canada']],
        'China' => ['CNY', ['China', "People's Republic of China", 'República Popular China']],
    ];

    /**
     * @return array<string, array{currency: string, names: list<string>}> canonical name => row
     */
    public static function all(): array
    {
        $rows = [];
        foreach (self::COUNTRIES as $country => [$currency, $names]) {
            $rows[$country] = ['currency' => $currency, 'names' => $names];
        }

        return $rows;
    }

    /**
     * The currency of a country by its canonical English name, or null.
     */
    public static function currencyOf(string $country): ?string
    {
        return self::COUNTRIES[$country][0] ?? null;
    }
}
