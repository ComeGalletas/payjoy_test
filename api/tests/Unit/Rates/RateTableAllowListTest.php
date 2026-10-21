<?php

declare(strict_types=1);

namespace FxInstallments\Tests\Unit\Rates;

use FxInstallments\Db\Connection;
use FxInstallments\Rates\FallbackCurrencies;
use FxInstallments\Rates\RateStore;
use FxInstallments\Rates\RateTable;
use FxInstallments\Rates\RateTableAllowList;
use FxInstallments\Tests\Support\TestEnvironment;
use FxInstallments\Validation\CurrencyValidator;
use FxInstallments\Validation\ValidationFailed;
use PDO;
use PHPUnit\Framework\TestCase;

final class RateTableAllowListTest extends TestCase
{
    private PDO $pdo;

    private RateStore $store;

    protected function setUp(): void
    {
        $this->pdo = TestEnvironment::connect(TestEnvironment::freshDatabasePath());
        $this->store = new RateStore(Connection::wrap($this->pdo));
    }

    public function testAnEmptyCacheFallsBackToTheFallbackCurrencyList(): void
    {
        $allowList = new RateTableAllowList($this->store);

        self::assertTrue($allowList->usesFallback());
        foreach (FallbackCurrencies::all() as $code) {
            self::assertTrue($allowList->contains($code), $code);
        }
        self::assertFalse($allowList->contains('XAU'));
        self::assertFalse($allowList->contains('XYZ'));
    }

    public function testAStoredTableIsTheAllowList(): void
    {
        $this->store->replace(new RateTable(['USD' => 10_000_000_000, 'BRL' => 52_291_000_000, 'XAU' => 3], 1, 2, 1));
        $allowList = new RateTableAllowList($this->store);

        self::assertFalse($allowList->usesFallback());
        self::assertTrue($allowList->contains('BRL'));
        self::assertTrue($allowList->contains('XAU'), 'upstream decides once a table is stored');
        self::assertFalse($allowList->contains('EUR'), 'a fallback code not in the table is not allowed');
    }

    public function testAnExpiredTableStillDefinesTheAllowList(): void
    {
        // Age doesn't matter: the validator classifies; the rate cache decides staleness.
        $this->store->replace(new RateTable(['COP' => 31_537_735_990_000], 1, 2, 1));

        self::assertTrue((new RateTableAllowList($this->store))->contains('COP'));
    }

    public function testTheListIsReadOncePerInstance(): void
    {
        $allowList = new RateTableAllowList($this->store);
        self::assertTrue($allowList->contains('EUR'));

        $this->store->replace(new RateTable(['USD' => 10_000_000_000], 1, 2, 1));

        self::assertTrue($allowList->contains('EUR'), 'one request sees one list');
        self::assertFalse((new RateTableAllowList($this->store))->contains('EUR'));
    }

    public function testInvalidStoredCodesAreIgnored(): void
    {
        $this->pdo->exec("INSERT INTO rate_cache VALUES ('brl', 1, 1, 2, 1), ('BRLX', 1, 1, 2, 1)");
        $allowList = new RateTableAllowList($this->store);

        self::assertTrue($allowList->usesFallback(), 'no valid code stored: the cache counts as empty');
        self::assertFalse($allowList->contains('brl'));
    }

    public function testTheValidatorUsesIt(): void
    {
        $validator = new CurrencyValidator(new RateTableAllowList($this->store));

        self::assertSame('PEN', $validator->validate(' pen '));
        try {
            $validator->validate('XYZ');
            self::fail('XYZ is not a currency');
        } catch (ValidationFailed $error) {
            self::assertSame(ValidationFailed::REASON_NOT_ALLOWED, $error->reason);
        }
    }
}
