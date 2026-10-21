<?php

declare(strict_types=1);

namespace FxInstallments\Tests\Acceptance;

use ArrayObject;
use FxInstallments\App;
use FxInstallments\Config\ConfigRepository;
use FxInstallments\Db\Connection;
use FxInstallments\Db\Retention;
use FxInstallments\Http\IpBlocks;
use FxInstallments\Http\JsonResponse;
use FxInstallments\Http\Kernel;
use FxInstallments\Http\Request;
use FxInstallments\Http\Router;
use FxInstallments\Tests\Support\FakeClock;
use FxInstallments\Tests\Support\OverridesEnvironment;
use FxInstallments\Tests\Support\TestEnvironment;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * P1-11 acceptance (docs/PLAN.md §10, ADR 0016):
 * - every request, including 404s, 429s and blocked ones, writes exactly one `requests` row
 *   with the client IP (the trusted-proxy XFF hop when configured), method, path without query
 *   and status;
 * - the configured number of 429s blocks the IP, and so does the configured number of error
 *   responses;
 * - a blocked IP gets 403 IP_BLOCKED with `Connection: close`, its handler never runs and no
 *   `error` event is written;
 * - the block expires after `block.duration_minutes`;
 * - `bin/ip.php unblock` restores access;
 * - rows older than 30 days are pruned (`bin/migrate.php --prune`).
 *
 * The kernel is App::kernel(), the production wiring, on a test route table; the two CLIs run
 * as separate processes on the same temp database.
 */
final class P1_11_RequestLogTest extends TestCase
{
    use OverridesEnvironment;

    private const CLIENT = '203.0.113.7';
    private const OTHER = '198.51.100.20';
    private const PROXY = '10.0.0.5';
    private const DAY = 86_400;

    private string $path;
    private PDO $pdo;
    private FakeClock $clock;
    private int $handlerRuns = 0;

    protected function setUp(): void
    {
        $this->path = TestEnvironment::freshDatabasePath();
        $this->pdo = TestEnvironment::connect($this->path);
        // The real time, so the CLIs (which use time()) and the kernel agree.
        $this->clock = new FakeClock(time());
    }

    public function testEveryRequestWritesExactlyOneRowWithTheClientIpMethodPathAndStatus(): void
    {
        $kernel = $this->kernel(['ratelimit.per_minute' => 1]);

        self::assertSame(200, $kernel->handle(new Request('GET', '/ok', ['token' => 'secret'], remoteAddr: self::CLIENT))->status());
        self::assertSame(404, $kernel->handle(new Request('POST', '/no/such/route?x=1', remoteAddr: self::CLIENT))->status());
        self::assertSame(200, $kernel->handle(new Request('GET', '/limited', remoteAddr: self::CLIENT))->status());
        self::assertSame(429, $kernel->handle(new Request('GET', '/limited', remoteAddr: self::CLIENT))->status());
        self::assertSame(500, $kernel->handle(new Request('GET', '/boom', remoteAddr: self::CLIENT))->status());
        $this->blockByHand(self::OTHER);
        self::assertSame(403, $kernel->handle(new Request('DELETE', '/ok?a=b', remoteAddr: self::OTHER))->status());

        self::assertSame([
            [self::CLIENT, 'GET', '/ok', 200, 0],
            [self::CLIENT, 'POST', '/no/such/route', 404, 0],
            [self::CLIENT, 'GET', '/limited', 200, 0],
            [self::CLIENT, 'GET', '/limited', 429, 0],
            [self::CLIENT, 'GET', '/boom', 500, 0],
            [self::OTHER, 'DELETE', '/ok', 403, 1],
        ], $this->requestRows());

        // Every row has the request's time and a latency; nothing stores the query string.
        foreach ($this->pdo->query('SELECT ts, latency_ms FROM requests')->fetchAll() as $row) {
            self::assertSame($this->clock->now, (int) $row['ts']);
            self::assertGreaterThanOrEqual(0, (int) $row['latency_ms']);
        }
        self::assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM requests WHERE path LIKE '%?%' OR path LIKE '%secret%'")->fetchColumn());
    }

    public function testBehindTheTrustedProxyTheRowHasTheForwardedHop(): void
    {
        $this->setEnvironment('TRUSTED_PROXY', self::PROXY);
        $kernel = $this->kernel();

        $kernel->handle(new Request('GET', '/ok', headers: ['X-Forwarded-For' => '192.0.2.99, ' . self::CLIENT], remoteAddr: self::PROXY));
        // A peer that isn't the trusted proxy is counted under its own address: its header is forged.
        $kernel->handle(new Request('GET', '/ok', headers: ['X-Forwarded-For' => self::CLIENT], remoteAddr: self::OTHER));

        self::assertSame([[self::CLIENT, 'GET', '/ok', 200, 0], [self::OTHER, 'GET', '/ok', 200, 0]], $this->requestRows());
    }

    /**
     * `block.rate_limit_hits` counts distinct rate-limited minutes (owner decision, 2026-10-21):
     * the IP must get 429s in that many different fixed UTC minutes within the window.
     */
    public function testTheConfiguredNumberOfRateLimitedMinutesBlocksTheIp(): void
    {
        $kernel = $this->kernel(['ratelimit.per_minute' => 2, 'block.rate_limit_hits' => 3]);
        $limited = new Request('GET', '/limited', remoteAddr: self::CLIENT);

        for ($minute = 1; $minute <= 3; $minute++) {
            self::assertSame(200, $kernel->handle($limited)->status());
            self::assertSame(200, $kernel->handle($limited)->status());
            // Several 429s in one minute count as one rate-limited minute.
            $hits = $minute < 3 ? 10 : 1;
            for ($hit = 1; $hit <= $hits; $hit++) {
                self::assertSame(429, $kernel->handle($limited)->status(), "minute {$minute}, 429 number {$hit}");
            }
            if ($minute < 3) {
                self::assertSame([], $this->blockRows(), "{$minute} rate-limited minute(s) are under three");
                $this->clock->advance(60);
            }
        }
        self::assertSame(
            [[self::CLIENT, 'auto', 'rate_limit_hits', $this->clock->now, $this->clock->now + 60 * 60]],
            $this->blockRows(),
        );

        // Blocked everywhere, not just on the rate-limited route; another IP is unaffected.
        $this->assertBlocked($kernel->handle(new Request('GET', '/ok', remoteAddr: self::CLIENT)));
        self::assertSame(200, $kernel->handle(new Request('GET', '/ok', remoteAddr: self::OTHER))->status());
    }

    public function testABurstInsideOneMinuteNeverBlocksWithTheDefaults(): void
    {
        $this->clock->now = intdiv(time(), 60) * 60; // the first second of a UTC minute
        $kernel = $this->kernel();
        $limited = new Request('GET', '/limited', remoteAddr: self::CLIENT);

        $statuses = [];
        for ($i = 1; $i <= 65; $i++) {
            $statuses[] = $kernel->handle($limited)->status();
        }

        self::assertSame(array_merge(array_fill(0, 30, 200), array_fill(0, 35, 429)), $statuses);
        self::assertSame([], $this->blockRows());
        self::assertSame(200, $kernel->handle(new Request('GET', '/ok', remoteAddr: self::CLIENT))->status());
    }

    public function testTheConfiguredNumberOfErrorResponsesBlocksTheIp(): void
    {
        $kernel = $this->kernel(['block.error_responses' => 4]);

        for ($i = 1; $i <= 3; $i++) {
            self::assertSame(404, $kernel->handle(new Request('GET', "/scan/{$i}", remoteAddr: self::CLIENT))->status());
        }
        self::assertSame([], $this->blockRows(), 'three error responses are under the threshold');
        self::assertSame(404, $kernel->handle(new Request('GET', '/scan/4', remoteAddr: self::CLIENT))->status());

        self::assertSame([[self::CLIENT, 'auto', 'error_responses', $this->clock->now, $this->clock->now + 60 * 60]], $this->blockRows());
        $this->assertBlocked($kernel->handle(new Request('GET', '/ok', remoteAddr: self::CLIENT)));
    }

    public function testABlockedIpGets403WithConnectionCloseNoHandlerAndNoErrorEvent(): void
    {
        $kernel = $this->kernel(['cors.allowed_origins' => ['https://shop.example']]);
        $this->blockByHand(self::CLIENT);
        $eventsBefore = $this->countRows('events');

        $this->assertBlocked($kernel->handle(new Request('GET', '/ok', remoteAddr: self::CLIENT)));
        $this->assertBlocked($kernel->handle(new Request('GET', '/boom', remoteAddr: self::CLIENT)));
        $this->assertBlocked($kernel->handle(new Request('GET', '/no/such/route', remoteAddr: self::CLIENT)));
        // Before routing, so before CORS preflights and every middleware too.
        $this->assertBlocked($kernel->handle(new Request('OPTIONS', '/cors', headers: [
            'Origin' => 'https://shop.example',
            'Access-Control-Request-Method' => 'POST',
        ], remoteAddr: self::CLIENT)));

        self::assertSame(0, $this->handlerRuns);
        self::assertSame($eventsBefore, $this->countRows('events'), 'blocked requests write no error event');
        self::assertSame(0, $this->countRows('rate_limits'), 'the rate limiter never ran');
        // The request-log row is the only write per blocked request.
        self::assertSame(
            [
                [self::CLIENT, 'GET', '/ok', 403, 1],
                [self::CLIENT, 'GET', '/boom', 403, 1],
                [self::CLIENT, 'GET', '/no/such/route', 403, 1],
                [self::CLIENT, 'OPTIONS', '/cors', 403, 1],
            ],
            $this->requestRows(),
        );
    }

    public function testTheBlockExpiresAfterTheConfiguredDuration(): void
    {
        $kernel = $this->kernel(['block.error_responses' => 1, 'block.duration_minutes' => 15]);
        self::assertSame(404, $kernel->handle(new Request('GET', '/nope', remoteAddr: self::CLIENT))->status());
        $blockedAt = $this->clock->now;

        $this->clock->now = $blockedAt + 15 * 60 - 1;
        $this->assertBlocked($kernel->handle(new Request('GET', '/ok', remoteAddr: self::CLIENT)));

        $this->clock->now = $blockedAt + 15 * 60;
        self::assertSame(200, $kernel->handle(new Request('GET', '/ok', remoteAddr: self::CLIENT))->status());
        self::assertSame(1, $this->handlerRuns);
    }

    public function testBinIpUnblockRestoresAccess(): void
    {
        // A minute ago, so the unblock (at the CLI's time()) is strictly after these requests.
        $this->clock->now = time() - 60;
        $kernel = $this->kernel(['block.error_responses' => 2]);
        $kernel->handle(new Request('GET', '/nope', remoteAddr: self::CLIENT));
        $kernel->handle(new Request('GET', '/nope', remoteAddr: self::CLIENT));
        $this->assertBlocked($kernel->handle(new Request('GET', '/ok', remoteAddr: self::CLIENT)));

        [$exit, $out, $err] = self::runCli('ip.php', ['list'], $this->path);
        self::assertSame(0, $exit, $err);
        self::assertStringContainsString(self::CLIENT . '  auto  since ', $out);
        self::assertStringEndsWith("ip: 1 active block(s), 0 manual without an expiry\n", $out);

        [$exit, $out, $err] = self::runCli('ip.php', ['unblock', self::CLIENT], $this->path);
        self::assertSame(0, $exit, $err);
        self::assertStringStartsWith('ip: unblocked ' . self::CLIENT . ' (was ' . self::CLIENT . '  auto  since ', $out);

        $this->clock->now = time();
        self::assertSame(200, $kernel->handle(new Request('GET', '/ok', remoteAddr: self::CLIENT))->status());
        self::assertSame(1, $this->handlerRuns);
        // The errors that earned the block were forgiven: one more doesn't re-block at once.
        self::assertSame(404, $kernel->handle(new Request('GET', '/nope', remoteAddr: self::CLIENT))->status());
        self::assertSame(200, $kernel->handle(new Request('GET', '/ok', remoteAddr: self::CLIENT))->status());

        self::assertSame("ip: no active blocks\n", self::runCli('ip.php', ['list'], $this->path)[1]);
    }

    public function testBinIpBlockBlocksByHandUntilUnblocked(): void
    {
        $kernel = $this->kernel();

        [$exit, $out, $err] = self::runCli('ip.php', ['block', self::CLIENT, '--reason=abuse report'], $this->path);
        self::assertSame(0, $exit, $err);
        self::assertSame('ip: blocked ' . self::CLIENT . " (manual) until unblocked\n", $out);

        $this->clock->now = time() + 400 * self::DAY;
        $this->assertBlocked($kernel->handle(new Request('GET', '/ok', remoteAddr: self::CLIENT)));

        [, $out] = self::runCli('ip.php', ['list'], $this->path);
        self::assertMatchesRegularExpression(
            '/^203\.0\.113\.7  manual  since \S+Z  until never  reason: abuse report\n'
            . 'ip: 1 active block\(s\), 1 manual without an expiry \(review them: they keep a raw IP until unblocked\)\n$/',
            $out,
        );

        self::assertSame(0, self::runCli('ip.php', ['unblock', self::CLIENT], $this->path)[0]);
        self::assertSame(200, $kernel->handle(new Request('GET', '/ok', remoteAddr: self::CLIENT))->status());
    }

    /**
     * The tester's round-1 repro: an unblock followed by a prune must keep the clean slate
     * (--prune keeps an ended block for block.window_minutes; owner decision, 2026-10-21).
     */
    public function testAPruneRightAfterAnUnblockKeepsTheCleanSlate(): void
    {
        $start = $this->clock->now;
        $kernel = $this->kernel(['block.error_responses' => 3]);
        for ($i = 0; $i < 3; $i++) {
            self::assertSame(404, $kernel->handle(new Request('GET', '/nope', remoteAddr: self::CLIENT))->status());
        }
        $this->assertBlocked($kernel->handle(new Request('GET', '/ok', remoteAddr: self::CLIENT)));

        self::assertNotNull((new IpBlocks(Connection::wrap($this->pdo)))->unblock(self::CLIENT, $start + 30));
        $pruned = (new Retention($this->pdo, 30, (new ConfigRepository())->int('block.window_minutes')))->prune($start + 31);
        self::assertSame(0, $pruned['ip_blocks'], 'the ended block is kept for one window');

        $this->clock->now = $start + 32;
        self::assertSame(404, $kernel->handle(new Request('GET', '/nope', remoteAddr: self::CLIENT))->status());
        self::assertSame(200, $kernel->handle(new Request('GET', '/ok', remoteAddr: self::CLIENT))->status(), 'not re-blocked');

        // A window after the unblock, the row goes; the old 404s have left the window too.
        self::assertSame(1, (new Retention($this->pdo, 30, 10))->prune($start + 30 + 600)['ip_blocks']);
    }

    /**
     * ip.block / ip.unblock events (owner decision, 2026-10-21) never hold the IP, in any
     * form, and neither does the error log. The reason text an operator types is not stored
     * in events either (it may contain an IP).
     */
    public function testBlockEventsAndTheErrorLogNeverHoldTheIp(): void
    {
        $log = new ArrayObject();
        $kernel = $this->kernel(['block.error_responses' => 2], $log);
        $v6 = '2001:db8::7';
        foreach ([self::CLIENT, $v6] as $ip) {
            $kernel->handle(new Request('GET', '/nope', remoteAddr: $ip));
            $kernel->handle(new Request('GET', '/nope', remoteAddr: $ip));
            $this->assertBlocked($kernel->handle(new Request('GET', '/ok', remoteAddr: $ip)));
        }
        [, , $err1] = self::runCli('ip.php', ['block', '2001:DB8:0:0::7', '--minutes=5', '--reason=seen with 198.51.100.99'], $this->path);
        [, , $err2] = self::runCli('ip.php', ['unblock', self::CLIENT], $this->path);
        [, , $err3] = self::runCli('ip.php', ['unblock', '192.0.2.44'], $this->path);

        $events = $this->pdo->query("SELECT * FROM events WHERE event_type LIKE 'ip.%' ORDER BY id")->fetchAll();
        self::assertSame(
            [
                ['ip.block', 'api', '{"source":"auto","reason":"error_responses","duration_minutes":60,"extended":false}'],
                ['ip.block', 'api', '{"source":"auto","reason":"error_responses","duration_minutes":60,"extended":false}'],
                ['ip.block', 'api', '{"source":"manual","reason":"given","duration_minutes":5}'],
                ['ip.unblock', 'api', '{"source":"auto","was_active":true}'],
                ['ip.unblock', 'api', '{"source":null,"was_active":false}'],
            ],
            array_map(static fn (array $row): array => [$row['event_type'], $row['source'], $row['meta']], $events),
        );

        $everything = $this->pdo->query('SELECT * FROM events')->fetchAll();
        $haystack = json_encode($everything, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)
            . "\n" . implode("\n", $log->getArrayCopy()) . "\n" . $err1 . $err2 . $err3;
        foreach ([
            self::CLIENT, '::ffff:' . self::CLIENT, '::ffff:cb00:7107', '203.0.113',
            $v6, '2001:DB8:0:0::7', '2001:db8:0:0:0:0:0:7', '2001:db8',
            '198.51.100.99', '192.0.2.44',
        ] as $form) {
            self::assertStringNotContainsStringIgnoringCase($form, $haystack, "the events or error log hold {$form}");
        }
    }

    public function testRowsOlderThan30DaysArePruned(): void
    {
        $kernel = $this->kernel();
        $now = time();

        $this->clock->now = $now - 30 * self::DAY - 3600;
        $kernel->handle(new Request('GET', '/old', remoteAddr: self::CLIENT));
        $this->clock->now = $now - 30 * self::DAY + 3600;
        $kernel->handle(new Request('GET', '/recent', remoteAddr: self::CLIENT));
        $this->clock->now = $now;
        $kernel->handle(new Request('GET', '/today', remoteAddr: self::CLIENT));

        [$exit, $out, $err] = self::runCli('migrate.php', ['--prune'], $this->path);

        self::assertSame(0, $exit, $err);
        self::assertStringContainsString("prune: requests 1 deleted\n", $out);
        self::assertSame(
            ['/recent', '/today'],
            $this->pdo->query('SELECT path FROM requests ORDER BY ts')->fetchAll(PDO::FETCH_COLUMN),
        );
    }

    private function assertBlocked(JsonResponse $response): void
    {
        self::assertSame(403, $response->status());
        self::assertSame('close', $response->header('Connection'));
        self::assertSame('application/json', $response->header('Content-Type'));
        self::assertSame('{"ok":false,"error":{"code":"IP_BLOCKED","message_for_user":"Too many unusual requests came from your network. Please try again later."}}', $response->body());
    }

    /**
     * App::kernel() (the production wiring) on a test route table.
     *
     * @param array<string, mixed> $config
     * @param ArrayObject<int, string>|null $log the error log
     */
    private function kernel(array $config = [], ?ArrayObject $log = null): Kernel
    {
        $app = TestEnvironment::app($this->clock, $config, $this->pdo, $log);

        return $app->kernel($this->router($app));
    }

    private function router(App $app): Router
    {
        $router = new Router();
        $ok = function (): JsonResponse {
            $this->handlerRuns++;

            return JsonResponse::ok(['ok' => true]);
        };
        $router->get('/ok', $ok);
        $router->get('/limited', $ok, [$app->rateLimiter()]);
        $router->post('/cors', $ok, [$app->cors()]);
        $router->get('/boom', function (): JsonResponse {
            $this->handlerRuns++;
            throw new RuntimeException('boom');
        });

        return $router;
    }

    private function blockByHand(string $ip): void
    {
        $this->pdo->prepare("INSERT INTO ip_blocks (ip, reason, source, blocked_at, expires_at) VALUES (?, 'test', 'manual', ?, NULL)")
            ->execute([$ip, $this->clock->now]);
    }

    /**
     * @return list<array{string, string, string, int, int}>
     */
    private function requestRows(): array
    {
        return array_map(
            static fn (array $row): array => [$row['ip'], $row['method'], $row['path'], (int) $row['status'], (int) $row['blocked']],
            $this->pdo->query('SELECT ip, method, path, status, blocked FROM requests ORDER BY id')->fetchAll(),
        );
    }

    /**
     * @return list<array{string, string, string, int, int|null}>
     */
    private function blockRows(): array
    {
        return array_map(
            static fn (array $row): array => [
                $row['ip'], $row['source'], $row['reason'], (int) $row['blocked_at'],
                $row['expires_at'] === null ? null : (int) $row['expires_at'],
            ],
            $this->pdo->query('SELECT ip, source, reason, blocked_at, expires_at FROM ip_blocks ORDER BY ip')->fetchAll(),
        );
    }

    private function countRows(string $table): int
    {
        return (int) $this->pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
    }

    /**
     * Runs a bin/ script as a separate process on the database at $path.
     *
     * @param list<string> $args
     * @return array{int, string, string} exit code, stdout, stderr
     */
    private static function runCli(string $script, array $args, string $path): array
    {
        $dir = TestEnvironment::tempDir('p1-11-cli');
        $env = getenv();
        $env['APP_ENV'] = 'test';
        $env['DB_DSN'] = 'sqlite:' . $path;
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__, 2) . '/bin/' . $script, ...$args],
            [0 => ['pipe', 'r'], 1 => ['file', $dir . '/out', 'w'], 2 => ['file', $dir . '/err', 'w']],
            $pipes,
            $dir,
            $env,
        );
        self::assertIsResource($process, "could not start bin/{$script}");
        fclose($pipes[0]);
        $exit = proc_close($process);

        return [$exit, (string) file_get_contents($dir . '/out'), (string) file_get_contents($dir . '/err')];
    }
}
