<?php

declare(strict_types=1);

namespace FxInstallments\Tests\Unit\Flow;

use FxInstallments\Flow\Collected;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CollectedTest extends TestCase
{
    public function testItRoundTripsOnlyTheCollectedFields(): void
    {
        $empty = new Collected();
        $full = $empty->withAmount(20_000)->withCurrency('BRL', 'Brazil')->withInstallments(3);

        self::assertSame('{}', $empty->toJson());
        self::assertSame('{"amount_usd_cents":20000,"currency":"BRL","country":"Brazil","installments":3}', $full->toJson());
        self::assertEquals($full, Collected::fromJson($full->toJson()));
        self::assertEquals($empty, Collected::fromJson('{}'));
        self::assertSame(['amount_usd_cents' => 20_000, 'currency' => 'BRL', 'country' => 'Brazil', 'installments' => 3], $full->eventFields());
        self::assertSame([true, true, true], [$full->has('amount'), $full->has('currency'), $full->has('installments')]);
    }

    public function testWithoutDropsAFieldAndTheCurrencyTakesItsCountry(): void
    {
        $full = (new Collected())->withAmount(1)->withCurrency('EUR', 'Spain')->withInstallments(2);

        self::assertSame(['currency' => 'EUR', 'country' => 'Spain', 'installments' => 2], $full->without('amount')->eventFields());
        self::assertSame(['amount_usd_cents' => 1, 'installments' => 2], $full->without('currency')->eventFields());
        self::assertSame(['amount_usd_cents' => 1, 'currency' => 'EUR', 'country' => 'Spain'], $full->without('installments')->eventFields());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function damaged(): iterable
    {
        yield 'not JSON' => ['{'];
        yield 'a list' => ['[1]'];
        yield 'a string' => ['"x"'];
        yield 'nested' => ['{"currency":{"code":"BRL"}}'];
        yield 'unknown key' => ['{"text":"brazil"}'];
        yield 'cents as text' => ['{"amount_usd_cents":"200"}'];
        yield 'cents as float' => ['{"amount_usd_cents":200.5}'];
        yield 'zero cents' => ['{"amount_usd_cents":0}'];
        yield 'lowercase code' => ['{"currency":"brl"}'];
        yield 'country without currency' => ['{"country":"Brazil"}'];
        yield "another currency's country" => ['{"currency":"EUR","country":"Brazil"}'];
        yield 'unknown country' => ['{"currency":"BRL","country":"brazil"}'];
        yield 'zero installments' => ['{"installments":0}'];
    }

    #[DataProvider('damaged')]
    public function testADamagedValueIsRefused(string $json): void
    {
        self::assertNull(Collected::fromJson($json));
    }
}
