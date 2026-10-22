<?php

declare(strict_types=1);

namespace FxInstallments\Tests\Unit;

use Closure;
use FxInstallments\Rates\ExchangeRateClient;
use FxInstallments\Tests\Support\MockUpstream;
use FxInstallments\Tests\Support\NetworkAccessForbidden;
use FxInstallments\Tests\Support\NetworkGuard;
use FxInstallments\Tests\Support\TestEnvironment;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\Handler\CurlMultiHandler;
use GuzzleHttp\Handler\StreamHandler;
use GuzzleHttp\HandlerStack;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * The Guzzle half of the no-network guard (INV-06, ADR 0018): a test that builds a real Guzzle
 * handler fails, and fails the run even when it swallows the exception.
 */
final class RealGuzzleHandlerGuardTest extends TestCase
{
    #[After]
    protected function clearGuardAttempts(): void
    {
        // The attempts below are deliberate; don't let them fail this run at shutdown.
        NetworkGuard::takeAttempts();
    }

    /**
     * @return iterable<string, array{Closure(): mixed, string}>
     */
    public static function realHandlers(): iterable
    {
        yield 'CurlHandler' => [static fn (): mixed => new CurlHandler(), 'guzzle://CurlHandler'];
        yield 'CurlMultiHandler' => [static fn (): mixed => new CurlMultiHandler(), 'guzzle://CurlMultiHandler'];
        yield 'StreamHandler' => [static fn (): mixed => new StreamHandler(), 'guzzle://StreamHandler'];
    }

    /**
     * @param Closure(): mixed $build
     */
    #[DataProvider('realHandlers')]
    public function testBuildingARealHandlerThrowsAndIsRecorded(Closure $build, string $attempt): void
    {
        try {
            $build();
            self::fail('a real handler was built');
        } catch (NetworkAccessForbidden $e) {
            self::assertStringStartsWith('INV-06: tests must not reach the network: a real Guzzle handler', $e->getMessage());
        }
        self::assertSame([$attempt], NetworkGuard::takeAttempts());
    }

    /**
     * @return iterable<string, array{Closure(): mixed}>
     */
    public static function defaultHandlerBuilders(): iterable
    {
        yield 'new Client()' => [static fn (): mixed => new Client()];
        yield 'new Client() with options' => [static fn (): mixed => new Client(['timeout' => 4])];
        yield 'HandlerStack::create()' => [static fn (): mixed => HandlerStack::create()];
        yield 'ExchangeRateClient::fromEnvironment()' => [static fn (): mixed => ExchangeRateClient::fromEnvironment()];
        // P1-06: App::rates() builds the client lazily, on the first refresh (a cold cache here).
        yield 'App::rates() refreshing without an injected client' => [static fn (): mixed => TestEnvironment::app()->rates()->rate('BRL')];
    }

    public function testAppRatesBuildsNoClientUntilARefresh(): void
    {
        TestEnvironment::app()->rates();

        self::assertSame([], NetworkGuard::takeAttempts());
    }

    /**
     * Under the gate (`-d disable_functions=curl_exec,curl_multi_exec -d allow_url_fopen=0`)
     * Guzzle finds no transport and refuses itself; without those flags it reaches a stub.
     * Either way nothing is built that could send a request.
     *
     * @param Closure(): mixed $build
     */
    #[DataProvider('defaultHandlerBuilders')]
    public function testBuildingADefaultHandlerFails(Closure $build): void
    {
        try {
            $build();
            self::fail('a default Guzzle handler was built');
        } catch (NetworkAccessForbidden) {
            self::assertTrue(function_exists('curl_exec'), 'only reachable while curl is enabled');
            self::assertNotSame([], NetworkGuard::takeAttempts());
        } catch (Throwable $e) {
            self::assertFalse(function_exists('curl_exec') && function_exists('curl_multi_exec'), 'Guzzle itself refuses only without curl');
            self::assertStringStartsWith('GuzzleHttp requires', $e->getMessage());
        }
    }

    public function testAMockHandlerIsUnaffected(): void
    {
        $upstream = new MockUpstream([MockUpstream::latest()]);

        self::assertCount(count(MockUpstream::RATES), $upstream->client()->latest()->rates);
        self::assertSame([], NetworkGuard::takeAttempts());
    }

    public function testASwallowedRealHandlerStillFailsTheRun(): void
    {
        // A separate PHPUnit process, curl enabled (so the stub is reached, as when PHPUnit is
        // run directly), running a test that builds `new Client()` and swallows the failure.
        $apiRoot = dirname(__DIR__, 2);
        $dir = TestEnvironment::tempDir('guard-probe');
        $process = proc_open(
            [
                PHP_BINARY, '-d', 'allow_url_fopen=0',
                $apiRoot . '/vendor/phpunit/phpunit/phpunit',
                '--configuration', $apiRoot . '/phpunit.xml',
                '--do-not-cache-result',
                $apiRoot . '/tests/Fixtures/Network/SwallowedRealHandlerProbe.php',
            ],
            [0 => ['pipe', 'r'], 1 => ['file', $dir . '/out', 'w'], 2 => ['file', $dir . '/err', 'w']],
            $pipes,
            $apiRoot,
        );
        self::assertIsResource($process);
        fclose($pipes[0]);
        $exit = proc_close($process);
        $out = (string) file_get_contents($dir . '/out');
        $err = (string) file_get_contents($dir . '/err');

        self::assertStringContainsString('OK (1 test, 1 assertion)', $out, 'the probe test itself passes');
        self::assertSame(1, $exit, $out . $err);
        self::assertStringContainsString('INV-06: tests attempted network access: guzzle://CurlMultiHandler', $err);
    }
}
