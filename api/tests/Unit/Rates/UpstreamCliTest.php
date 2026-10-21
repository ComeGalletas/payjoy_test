<?php

declare(strict_types=1);

namespace FxInstallments\Tests\Unit\Rates;

use Closure;
use FxInstallments\Db\Connection;
use FxInstallments\Metrics\ErrorCodes;
use FxInstallments\Rates\RateStore;
use FxInstallments\Rates\RateTable;
use FxInstallments\Rates\UpstreamBreaker;
use FxInstallments\Rates\UpstreamError;
use FxInstallments\Tests\Support\FakeClock;
use FxInstallments\Tests\Support\MockUpstream;
use FxInstallments\Tests\Support\TestEnvironment;
use PHPUnit\Framework\TestCase;

/**
 * `php bin/upstream.php status | reset`, run as a separate process on a temp database.
 */
final class UpstreamCliTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = TestEnvironment::freshDatabasePath();
    }

    public function testStatusOnAFreshDatabase(): void
    {
        [$exit, $out, $err] = $this->runCli(['status']);

        self::assertSame(0, $exit, $err);
        self::assertSame(
            "breaker: closed\nrates: cache empty (the fallback currency list is the allow-list)\napi key: set\n",
            $out,
        );
        self::assertSame('', $err);
    }

    public function testStatusShowsTheBreakerAndTheTableButNeverTheKey(): void
    {
        $db = Connection::wrap(TestEnvironment::connect($this->path));
        $clock = new FakeClock(gmmktime(10, 30, 0, 10, 21, 2026));
        (new UpstreamBreaker($db, Closure::fromCallable($clock)))->trip(new UpstreamError(ErrorCodes::UPSTREAM_QUOTA, 'quota-reached'));
        (new RateStore($db))->replace(new RateTable(['USD' => 10_000_000_000, 'BRL' => 52_291_000_000], MockUpstream::LAST_UPDATE, MockUpstream::NEXT_UPDATE, MockUpstream::LAST_UPDATE + 60));

        [$exit, $out, $err] = $this->runCli(['status']);

        self::assertSame(0, $exit, $err);
        self::assertMatchesRegularExpression(
            '/^breaker: (open|expired) \(quota, UPSTREAM_QUOTA\) since 2026-10-21T10:30:00Z until 2026-11-01T00:00:00Z$/m',
            $out,
        );
        self::assertStringContainsString(
            "rates: 2 currencies, rate_as_of 2026-10-21T00:00:01Z, next_update 2026-10-22T00:00:01Z, fetched_at 2026-10-21T00:01:01Z\n",
            $out,
        );
        self::assertStringNotContainsString(MockUpstream::CANARY_KEY, $out . $err);
    }

    public function testResetClearsAnAuthBlock(): void
    {
        $db = Connection::wrap(TestEnvironment::connect($this->path));
        (new UpstreamBreaker($db))->trip(new UpstreamError(ErrorCodes::UPSTREAM_AUTH, 'invalid-key'));

        [$exit, $out] = $this->runCli(['reset']);

        self::assertSame(0, $exit);
        self::assertMatchesRegularExpression('/^upstream: breaker reset \(was open \(auth, UPSTREAM_AUTH\) since \S+ until reset\)$/', trim($out));
        self::assertNull((new UpstreamBreaker($db))->state());

        [$exit, $out] = $this->runCli(['reset']);
        self::assertSame(0, $exit);
        self::assertSame("upstream: breaker was already closed\n", $out);
    }

    public function testStatusSaysWhenNoKeyIsSet(): void
    {
        [$exit, $out] = $this->runCli(['status'], key: '');

        self::assertSame(0, $exit);
        self::assertStringEndsWith("api key: not set\n", $out);
    }

    public function testBadArgumentsPrintUsage(): void
    {
        foreach ([[], ['quota'], ['reset', 'now'], ['--help']] as $args) {
            [$exit, $out, $err] = $this->runCli($args);
            self::assertSame(2, $exit);
            self::assertSame('', $out);
            self::assertSame("usage: php bin/upstream.php status | reset\n", $err);
        }
    }

    public function testADatabaseBehindTheCodeIsRefused(): void
    {
        $path = TestEnvironment::tempDir('cli-empty') . '/empty.db';
        touch($path);

        [$exit, $out, $err] = $this->runCli(['status'], path: $path);

        self::assertSame(1, $exit);
        self::assertSame('', $out);
        self::assertSame("upstream: failed: FxInstallments\\Db\\SchemaOutOfDate\n", $err);
    }

    /**
     * @param list<string> $args
     * @return array{int, string, string}
     */
    private function runCli(array $args, ?string $path = null, string $key = MockUpstream::CANARY_KEY): array
    {
        $dir = TestEnvironment::tempDir('upstream-cli');
        $env = getenv();
        $env['APP_ENV'] = 'test';
        $env['DB_DSN'] = 'sqlite:' . ($path ?? $this->path);
        $env['EXCHANGERATE_API_KEY'] = $key;
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__, 3) . '/bin/upstream.php', ...$args],
            [0 => ['pipe', 'r'], 1 => ['file', $dir . '/out', 'w'], 2 => ['file', $dir . '/err', 'w']],
            $pipes,
            $dir,
            $env,
        );
        self::assertIsResource($process, 'could not start bin/upstream.php');
        fclose($pipes[0]);
        $exit = proc_close($process);

        return [$exit, (string) file_get_contents($dir . '/out'), (string) file_get_contents($dir . '/err')];
    }
}
