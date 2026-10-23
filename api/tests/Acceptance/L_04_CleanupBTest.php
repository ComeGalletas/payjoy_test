<?php

declare(strict_types=1);

namespace FxInstallments\Tests\Acceptance;

use FxInstallments\Config\ConfigRepository;
use FxInstallments\Http\JsonResponse;
use FxInstallments\Http\Kernel;
use FxInstallments\Http\Request;
use FxInstallments\Http\Router;
use FxInstallments\Tests\Support\FakeClock;
use FxInstallments\Tests\Support\MockUpstream;
use FxInstallments\Tests\Support\OverridesEnvironment;
use FxInstallments\Tests\Support\TestEnvironment;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * L-04 cleanup B (docs/PLAN.md L-04), the parts that change behaviour, through the real
 * entry points:
 *
 * - **IPv4-mapped addresses** (owner decision, ADR 0016 fifth amendment): a proxy reached over
 *   a dual-stack socket (`REMOTE_ADDR=::ffff:10.0.0.2`, `TRUSTED_PROXY=10.0.0.2`, or the
 *   reverse) is the trusted proxy: its `X-Forwarded-For` hop is the client, and the proxy's
 *   own address is never blocked automatically. Another mapped address is not the proxy.
 * - **`php api/bin/` usage lines:** every CLI's usage line names the path you run it by from
 *   the repository root.
 * - **The default config and the demo seed still load** now that a non-integer `amount.max`
 *   is refused when the config loads.
 *
 * The unit tests carry the rest (SmokeTest for PHP 8.3 only, ClientIpTest, IpBlockingTest,
 * ConfigRepositoryTest, DatabaseTest for the no-create flag, FlowEngineTest for the sweep's
 * close told by `expires_at`, ReportQueryTest and P1_09_SeedAndDocsTest for the notes).
 * Every subprocess runs with ADR 0018's gate flags, APP_ENV=test and a canary key behind a
 * closed proxy port.
 */
final class L_04_CleanupBTest extends TestCase
{
    use OverridesEnvironment;

    private const GATE_FLAGS = ['-d', 'allow_url_fopen=0', '-d', 'disable_functions=curl_exec,curl_multi_exec'];

    private const CLIENT = '203.0.113.7';

    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = TestEnvironment::connect(TestEnvironment::freshDatabasePath());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function dualStackProxies(): iterable
    {
        yield 'TRUSTED_PROXY IPv4, REMOTE_ADDR IPv4-mapped' => ['10.0.0.2', '::ffff:10.0.0.2'];
        yield 'TRUSTED_PROXY IPv4-mapped, REMOTE_ADDR IPv4' => ['::ffff:10.0.0.2', '10.0.0.2'];
    }

    #[DataProvider('dualStackProxies')]
    public function testAProxyOnADualStackSocketIsBelieved(string $trustedProxy, string $peer): void
    {
        $this->setEnvironment('TRUSTED_PROXY', $trustedProxy);
        $kernel = $this->kernel();

        $kernel->handle(new Request('GET', '/ok', headers: ['X-Forwarded-For' => '192.0.2.99, ' . self::CLIENT], remoteAddr: $peer));
        // Another IPv4-mapped address is not the proxy: its header is ignored.
        $kernel->handle(new Request('GET', '/ok', headers: ['X-Forwarded-For' => self::CLIENT], remoteAddr: '::ffff:10.0.0.3'));

        self::assertSame([self::CLIENT, '::ffff:10.0.0.3'], $this->pdo->query('SELECT ip FROM requests ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
    }

    #[DataProvider('dualStackProxies')]
    public function testTheProxyIsNeverBlockedAutomaticallyInEitherForm(string $trustedProxy, string $peer): void
    {
        $this->setEnvironment('TRUSTED_PROXY', $trustedProxy);
        $kernel = $this->kernel(['ratelimit.per_minute' => 1, 'block.rate_limit_hits' => 1]);

        // Without a hop, the proxy's own requests count under its address: rate-limited, never
        // blocked. The neighbouring mapped address is blocked on its first 429.
        foreach ([$peer, '::ffff:10.0.0.3'] as $address) {
            self::assertSame(200, $kernel->handle(new Request('GET', '/limited', remoteAddr: $address))->status());
            self::assertSame(429, $kernel->handle(new Request('GET', '/limited', remoteAddr: $address))->status());
        }

        self::assertSame(['::ffff:10.0.0.3'], $this->pdo->query('SELECT ip FROM ip_blocks')->fetchAll(PDO::FETCH_COLUMN));
        self::assertSame(429, $kernel->handle(new Request('GET', '/limited', remoteAddr: $peer))->status(), 'still not blocked');
    }

    /**
     * @return iterable<string, array{string, list<string>, string}>
     */
    public static function usageLines(): iterable
    {
        yield 'migrate' => ['bin/migrate.php', ['--bogus'], "usage: php api/bin/migrate.php [--prune | --dump]\n"];
        yield 'bot' => ['bin/bot.php', ['x'], "usage: php api/bin/bot.php\n"];
        yield 'ip' => ['bin/ip.php', [], "usage: php api/bin/ip.php block <ip> [--minutes=N] [--reason=TEXT] | unblock <ip> | list\n"];
        yield 'report' => ['bin/report.php', ['--bogus'], "usage: php api/bin/report.php [--from=YYYY-MM-DD] [--to=YYYY-MM-DD] [--format=table|csv|json]\n"];
        yield 'upstream' => ['bin/upstream.php', [], "usage: php api/bin/upstream.php status | reset\n"];
        yield 'seed_demo' => ['db/seed_demo.php', ['x'], "usage: php api/db/seed_demo.php\n"];
    }

    /**
     * @param list<string> $args
     */
    #[DataProvider('usageLines')]
    public function testEveryUsageLineNamesThePathFromTheRepositoryRoot(string $script, array $args, string $stderr): void
    {
        self::assertSame([2, '', $stderr], self::runScript($script, $args, TestEnvironment::freshDatabasePath()));
        self::assertStringNotContainsString('php bin/', (string) file_get_contents(dirname(__DIR__, 2) . '/' . $script));
    }

    public function testTheDefaultConfigAndTheDemoSeedStillLoad(): void
    {
        self::assertSame(1_000_000, (new ConfigRepository())->int('amount.max'));

        // Both read the default config: --prune for its retention keys, seed_demo for the rest.
        $path = TestEnvironment::freshDatabasePath();
        [$exit, $out, $err] = self::runScript('db/seed_demo.php', [], $path);
        self::assertSame([0, ''], [$exit, $err]);
        self::assertStringStartsWith('seed_demo: wrote 14 sessions, ', $out);
        [$exit, , $err] = self::runScript('bin/migrate.php', ['--prune'], $path);
        self::assertSame([0, ''], [$exit, $err]);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function kernel(array $config = []): Kernel
    {
        $app = TestEnvironment::app(new FakeClock(time()), $config, $this->pdo);
        $router = new Router();
        $ok = static fn (): JsonResponse => JsonResponse::ok(['ok' => true]);
        $router->get('/ok', $ok);
        $router->get('/limited', $ok, [$app->rateLimiter()]);

        return $app->kernel($router);
    }

    /**
     * Runs api/$script with DB_DSN on $path.
     *
     * @param list<string> $args
     * @return array{int, string, string}
     */
    private static function runScript(string $script, array $args, string $path): array
    {
        $dir = TestEnvironment::tempDir('l04b-cli');
        file_put_contents($dir . '/in', '');
        $env = getenv();
        $env['APP_ENV'] = 'test';
        $env['DB_DSN'] = 'sqlite:' . $path;
        $env['EXCHANGERATE_API_KEY'] = MockUpstream::CANARY_KEY;
        $env['HTTPS_PROXY'] = $env['HTTP_PROXY'] = $env['https_proxy'] = $env['http_proxy'] = 'http://127.0.0.1:9';
        unset($env['NO_PROXY'], $env['no_proxy']);
        $process = proc_open(
            [PHP_BINARY, ...self::GATE_FLAGS, dirname(__DIR__, 2) . '/' . $script, ...$args],
            [0 => ['file', $dir . '/in', 'r'], 1 => ['file', $dir . '/out', 'w'], 2 => ['file', $dir . '/err', 'w']],
            $pipes,
            $dir,
            $env,
        );
        self::assertIsResource($process, 'could not start ' . $script);
        $exit = proc_close($process);

        return [$exit, (string) file_get_contents($dir . '/out'), (string) file_get_contents($dir . '/err')];
    }
}
