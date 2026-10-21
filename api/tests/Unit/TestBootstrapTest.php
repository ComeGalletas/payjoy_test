<?php

declare(strict_types=1);

namespace FxInstallments\Tests\Unit;

use FxInstallments\Env;
use FxInstallments\Tests\Support\NetworkAccessForbidden;
use FxInstallments\Tests\Support\NetworkGuard;
use FxInstallments\Tests\Support\TestEnvironment;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The test bootstrap's promises: test mode, fake key, empty admin token, temp protected folders,
 * temp database and no network (INV-06, GR-001).
 */
final class TestBootstrapTest extends TestCase
{
    #[After]
    protected function clearGuardAttempts(): void
    {
        // The attempts below are deliberate; don't let them fail the run at shutdown.
        NetworkGuard::takeAttempts();
    }

    public function testTheSuiteRunsInTestModeWithTheFakeKey(): void
    {
        self::assertSame('test', Env::get('APP_ENV'));
        self::assertSame('test-fake-key-not-real', Env::get('EXCHANGERATE_API_KEY'));
        self::assertSame('', $_ENV['ADMIN_TOKEN']);
        self::assertNull(Env::nonEmpty('ADMIN_TOKEN'));
    }

    public function testProtectedFoldersPointAtFreshTempDirs(): void
    {
        foreach (['DATA_ROOT', 'BACKUP_ROOT'] as $name) {
            $dir = Env::get($name);
            self::assertIsString($dir);
            self::assertDirectoryExists($dir);
            self::assertStringStartsWith(TestEnvironment::root() . '/', $dir, $name);
        }
        self::assertNotSame(Env::get('DATA_ROOT'), Env::get('BACKUP_ROOT'));
    }

    public function testTheDatabaseIsATempFileNeverAppDb(): void
    {
        $dsn = (string) Env::get('DB_DSN');

        self::assertStringStartsWith('sqlite:' . TestEnvironment::root() . '/', $dsn);
        self::assertStringNotContainsString('app.db', $dsn);
    }

    #[DataProvider('networkCalls')]
    public function testOpeningANetworkStreamFailsLoudly(string $scheme, \Closure $call): void
    {
        try {
            $call();
            self::fail("{$scheme} access should have been blocked");
        } catch (NetworkAccessForbidden $e) {
            self::assertStringStartsWith('INV-06: tests must not reach the network', $e->getMessage());
            self::assertStringNotContainsString('secret-path', $e->getMessage());
        }
        self::assertNotSame([], NetworkGuard::takeAttempts());
    }

    /**
     * @return iterable<string, array{string, \Closure(): mixed}>
     */
    public static function networkCalls(): iterable
    {
        yield 'file_get_contents https' => ['https', static fn (): mixed => file_get_contents('https://v6.exchangerate-api.com/v6/secret-path/latest/USD')];
        yield 'fopen http' => ['http', static fn (): mixed => fopen('http://example.com/secret-path', 'r')];
        yield 'file http' => ['http', static fn (): mixed => file('http://example.com/secret-path')];
        yield 'file_exists https' => ['https', static fn (): mixed => file_exists('https://example.com/secret-path')];
        yield 'ftp' => ['ftp', static fn (): mixed => file_get_contents('ftp://example.com/secret-path')];
    }

    public function testTheGuardIsInstalledForEveryNetworkScheme(): void
    {
        self::assertTrue(NetworkGuard::isInstalled());
        foreach (NetworkGuard::SCHEMES as $scheme) {
            self::assertContains($scheme, stream_get_wrappers());
        }
    }

    public function testTheGateFlagsAreOnForTheSuite(): void
    {
        // Both are PHP_INI_SYSTEM: only the command line can set them (composer test/acceptance,
        // ADR 0018). Run directly, PHPUnit skips this one test; the stream-wrapper and Guzzle
        // handler guards still apply.
        if (ini_get('allow_url_fopen') !== '0') {
            self::markTestSkipped('PHPUnit was started without -d allow_url_fopen=0; run it through '
                . '`make check` or `composer test`. The stream-wrapper and Guzzle handler guards still apply.');
        }
        self::assertSame('0', ini_get('allow_url_fopen'));
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        self::assertContains('curl_exec', $disabled);
        self::assertContains('curl_multi_exec', $disabled);
        self::assertFalse(function_exists('curl_exec'));
        self::assertFalse(function_exists('curl_multi_exec'));
    }
}
