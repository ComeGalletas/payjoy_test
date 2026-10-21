<?php

declare(strict_types=1);

namespace FxInstallments\Tests\Unit\Rates;

use FxInstallments\Convert\ScaledRate;
use FxInstallments\Rates\ConversionRatesScanner;
use FxInstallments\Tests\Support\MockUpstream;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The decimal-text scanner (ADR 0017 amendment): upstream's own number text for every rate.
 */
final class ConversionRatesScannerTest extends TestCase
{
    public function testReadsEveryRateTextFromUpstreamsShape(): void
    {
        $scan = ConversionRatesScanner::scan(MockUpstream::latestBody());

        self::assertNotNull($scan);
        self::assertSame([
            'USD' => '1',
            'BRL' => '5.2291',
            'COP' => '3153.773599',
            'EUR' => '0.8594',
            'JPY' => '151.468',
            'KWD' => '0.309652',
            'CLF' => '0.024825',
            'IRR' => '1731936.861971',
            'TRY' => '41.9532',
            'ALL' => '83.2014',
        ], $scan['rates']);
        self::assertSame([], $scan['duplicates']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function numberTexts(): iterable
    {
        foreach (['1731936.861971', '0.000001', '9999999.999999', '1', '0', '-5.2', '1e5', '1E5', '1.5e-3',
            '2E+2', '1.0e-10', '12345678901234567890', '0.12345678901234567'] as $text) {
            yield $text => [$text];
        }
    }

    #[DataProvider('numberTexts')]
    public function testKeepsTheNumberTextExactly(string $text): void
    {
        $scan = ConversionRatesScanner::scan('{"conversion_rates":{"XXA":' . $text . '}}');

        self::assertNotNull($scan);
        self::assertSame(['XXA' => $text], $scan['rates']);
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function textsTheFloatRouteGetsWrong(): iterable
    {
        yield '0.123456789049999999' => ['0.123456789049999999', 1_234_567_890];
        yield '1.00000000004999999' => ['1.00000000004999999', 10_000_000_000];
        yield '3153.77359900004999999' => ['3153.77359900004999999', 31_537_735_990_000];
    }

    #[DataProvider('textsTheFloatRouteGetsWrong')]
    public function testTheScannedTextScalesExactly(string $text, int $scaled): void
    {
        $scan = ConversionRatesScanner::scan(MockUpstream::latestBody(['USD' => 1, 'BRL' => $text]));

        self::assertNotNull($scan);
        self::assertSame($text, $scan['rates']['BRL']);
        self::assertSame($scaled, ScaledRate::fromDecimalString($scan['rates']['BRL']));
        self::assertNotSame($scaled, ScaledRate::fromRate((float) $text));
    }

    public function testSkipsEveryOtherMemberWhateverItHolds(): void
    {
        $json = '{"a":"x \" } ] { [ \\\\","b":[1,{"c":[]},"}"],"d":{"conversion_rates":{"NO":1}},'
            . '"e":true,"f":null,"g":-1.5e3,"conversion_rates":{"BRL":5.2291},"h":false}';

        $scan = ConversionRatesScanner::scan($json);

        self::assertNotNull($scan);
        self::assertSame(['BRL' => '5.2291'], $scan['rates'], 'a nested conversion_rates is not the top-level one');
    }

    public function testNonNumberValuesAreNullAndDuplicatesAreFlagged(): void
    {
        $scan = ConversionRatesScanner::scan(
            '{"conversion_rates":{"A":"5","B":null,"C":{"x":[1]},"D":[2],"E":true,"BRL":1,"BRL":2}}',
        );

        self::assertNotNull($scan);
        self::assertSame(['A' => null, 'B' => null, 'C' => null, 'D' => null, 'E' => null, 'BRL' => '2'], $scan['rates']);
        self::assertSame(['BRL' => true], $scan['duplicates']);
    }

    public function testKeysAreDecodedLikeJsonDecode(): void
    {
        $json = '{"conversion_rates":{"BRL":5,"123":6,"café":7}}';
        $scan = ConversionRatesScanner::scan($json);
        $decoded = json_decode($json, true, 16, JSON_THROW_ON_ERROR)['conversion_rates'];

        self::assertNotNull($scan);
        self::assertSame(array_keys($decoded), array_keys($scan['rates']));
        self::assertSame(['BRL', 123, 'café'], array_keys($scan['rates']));
    }

    public function testWhitespaceAnywhereIsAllowed(): void
    {
        $scan = ConversionRatesScanner::scan(" \n{ \"conversion_rates\" :\t{ \"BRL\" : 5.2291 ,\r\n \"USD\":1 } } \n");

        self::assertNotNull($scan);
        self::assertSame(['BRL' => '5.2291', 'USD' => '1'], $scan['rates']);
    }

    public function testAnEmptyRatesObjectScans(): void
    {
        self::assertSame(['rates' => [], 'duplicates' => []], ConversionRatesScanner::scan('{"conversion_rates":{}}'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unscannable(): iterable
    {
        yield 'empty' => [''];
        yield 'not an object' => ['[{"conversion_rates":{}}]'];
        yield 'no conversion_rates' => ['{"rates":{"BRL":5}}'];
        yield 'empty object' => ['{}'];
        yield 'conversion_rates twice' => ['{"conversion_rates":{"BRL":5},"conversion_rates":{"BRL":6}}'];
        yield 'conversion_rates is a list' => ['{"conversion_rates":[5]}'];
        yield 'conversion_rates is a number' => ['{"conversion_rates":5}'];
        yield 'trailing text' => ['{"conversion_rates":{"BRL":5}} x'];
        yield 'two documents' => ['{"conversion_rates":{"BRL":5}}{}'];
        yield 'truncated' => ['{"conversion_rates":{"BRL":5'];
        yield 'unterminated string' => ['{"conversion_rates":{"BRL'];
        yield 'missing colon' => ['{"conversion_rates" {"BRL":5}}'];
        yield 'trailing comma' => ['{"conversion_rates":{"BRL":5,}}'];
        yield 'bad escape in key' => ['{"conversion_rates":{"\x":5}}'];
        yield 'single quotes' => ["{'conversion_rates':{'BRL':5}}"];
        yield 'unclosed nested value' => ['{"a":[1,2,"conversion_rates":{"BRL":5}}'];
        yield 'bare word' => ['{"a":nope,"conversion_rates":{"BRL":5}}'];
    }

    #[DataProvider('unscannable')]
    public function testAnythingItDoesNotUnderstandGivesNull(string $json): void
    {
        self::assertNull(ConversionRatesScanner::scan($json));
    }

    public function testALargeBodyScansWithoutRecursion(): void
    {
        // Deep nesting elsewhere and a long string do not matter: skipped values are walked
        // iteratively and strings matched possessively.
        $json = '{"deep":' . str_repeat('[', 5000) . str_repeat(']', 5000) . ',"long":"' . str_repeat('x', 200_000)
            . '","conversion_rates":{"BRL":5.2291}}';

        $scan = ConversionRatesScanner::scan($json);

        self::assertNotNull($scan);
        self::assertSame(['BRL' => '5.2291'], $scan['rates']);
    }
}
