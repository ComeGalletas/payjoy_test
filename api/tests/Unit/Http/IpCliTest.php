<?php

declare(strict_types=1);

namespace FxInstallments\Tests\Unit\Http;

use FxInstallments\Http\IpBlocking;
use FxInstallments\Tests\Support\TestEnvironment;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `php bin/ip.php block | unblock | list`, run as a separate process on a temp database.
 */
final class IpCliTest extends TestCase
{
    private const USAGE = "usage: php bin/ip.php block <ip> [--minutes=N] [--reason=TEXT] | unblock <ip> | list\n";

    private string $path;
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->path = TestEnvironment::freshDatabasePath();
        $this->pdo = TestEnvironment::connect($this->path);
    }

    public function testListOnAFreshDatabase(): void
    {
        self::assertSame([0, "ip: no active blocks\n", ''], $this->runCli(['list']));
    }

    public function testBlockWithMinutesAndReason(): void
    {
        $before = time();
        [$exit, $out, $err] = $this->runCli(['block', '203.0.113.7', '--minutes=90', '--reason=too many scans']);
        $after = time();

        self::assertSame(0, $exit, $err);
        self::assertMatchesRegularExpression('/^ip: blocked 203\.0\.113\.7 \(manual\) until \d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ\n$/', $out);
        $row = $this->pdo->query('SELECT ip, reason, source, blocked_at, expires_at FROM ip_blocks')->fetch();
        self::assertSame(['203.0.113.7', 'too many scans', 'manual'], [$row['ip'], $row['reason'], $row['source']]);
        self::assertGreaterThanOrEqual($before, (int) $row['blocked_at']);
        self::assertLessThanOrEqual($after, (int) $row['blocked_at']);
        self::assertSame((int) $row['blocked_at'] + 90 * 60, (int) $row['expires_at']);
    }

    public function testBlockWithoutAnExpiryDefaultsTheReason(): void
    {
        [$exit, $out] = $this->runCli(['block', '2001:DB8:0:0::1']);

        self::assertSame(0, $exit);
        // Stored in canonical form, as REMOTE_ADDR reports it.
        self::assertSame("ip: blocked 2001:db8::1 (manual) until unblocked\n", $out);
        self::assertSame(
            [['ip' => '2001:db8::1', 'reason' => 'manual', 'source' => 'manual', 'expires_at' => null]],
            $this->pdo->query('SELECT ip, reason, source, expires_at FROM ip_blocks')->fetchAll(),
        );
    }

    public function testAManualBlockReplacesAnActiveAutomaticOne(): void
    {
        $this->pdo->prepare("INSERT INTO ip_blocks (ip, reason, source, blocked_at, expires_at) VALUES ('203.0.113.7', 'rate_limit_hits', 'auto', ?, ?)")
            ->execute([time() - 60, time() + 3000]);

        self::assertSame(0, $this->runCli(['block', '203.0.113.7'])[0]);

        self::assertSame(
            [['source' => 'manual', 'expires_at' => null]],
            $this->pdo->query('SELECT source, expires_at FROM ip_blocks')->fetchAll(),
        );
    }

    public function testListShowsActiveBlocksAndCountsThoseWithoutAnExpiry(): void
    {
        $insert = $this->pdo->prepare('INSERT INTO ip_blocks (ip, reason, source, blocked_at, expires_at) VALUES (?, ?, ?, ?, ?)');
        $insert->execute(['198.51.100.1', 'rate_limit_hits', 'auto', gmmktime(10, 0, 0, 10, 21, 2026), time() + 600]);
        $insert->execute(['198.51.100.2', 'abuse report', 'manual', gmmktime(9, 0, 0, 10, 21, 2026), null]);
        $insert->execute(['198.51.100.3', 'old', 'manual', gmmktime(8, 0, 0, 10, 21, 2026), time() - 1]);

        [$exit, $out] = $this->runCli(['list']);

        self::assertSame(0, $exit);
        self::assertSame(
            "198.51.100.2  manual  since 2026-10-21T09:00:00Z  until never  reason: abuse report\n"
            . '198.51.100.1  auto  since 2026-10-21T10:00:00Z  until ' . gmdate('Y-m-d\TH:i:s\Z', time() + 600) . "  reason: rate_limit_hits\n"
            . "ip: 2 active block(s), 1 manual without an expiry (review them: they keep a raw IP until unblocked)\n",
            $out,
        );
    }

    public function testUnblock(): void
    {
        $this->pdo->exec("INSERT INTO ip_blocks (ip, reason, source, blocked_at, expires_at) VALUES ('203.0.113.7', 'abuse', 'manual', 1790000000, NULL)");

        [$exit, $out] = $this->runCli(['unblock', '203.0.113.7']);
        self::assertSame(0, $exit);
        self::assertSame("ip: unblocked 203.0.113.7 (was 203.0.113.7  manual  since 2026-09-21T14:13:20Z  until never  reason: abuse)\n", $out);
        self::assertNotNull($this->pdo->query('SELECT expires_at FROM ip_blocks')->fetchColumn());

        self::assertSame([0, "ip: 203.0.113.7 had no active block\n", ''], $this->runCli(['unblock', '203.0.113.7']));
        self::assertSame("ip: no active blocks\n", $this->runCli(['list'])[1]);
    }

    /**
     * @return iterable<string, array{list<string>, string}>
     */
    public static function badArguments(): iterable
    {
        yield 'nothing' => [[], ''];
        yield 'unknown command' => [['show'], ''];
        yield 'list with an argument' => [['list', 'all'], ''];
        yield 'block without an ip' => [['block'], ''];
        yield 'unblock without an ip' => [['unblock'], ''];
        yield 'unblock with an option' => [['unblock', '203.0.113.7', '--minutes=5'], ''];
        yield 'unknown option' => [['block', '203.0.113.7', '--hours=5'], ''];
        yield 'repeated option' => [['block', '203.0.113.7', '--minutes=5', '--minutes=6'], ''];
        yield 'option without =' => [['block', '203.0.113.7', '--minutes', '5'], ''];
        yield 'not an ip' => [['block', 'example.com'], "ip: not a valid IP address\n"];
        yield 'ip with a port' => [['unblock', '203.0.113.7:80'], "ip: not a valid IP address\n"];
        yield 'zero minutes' => [['block', '203.0.113.7', '--minutes=0'], "ip: --minutes must be a whole number from 1 to 525600\n"];
        yield 'negative minutes' => [['block', '203.0.113.7', '--minutes=-5'], "ip: --minutes must be a whole number from 1 to 525600\n"];
        yield 'fractional minutes' => [['block', '203.0.113.7', '--minutes=1.5'], "ip: --minutes must be a whole number from 1 to 525600\n"];
        yield 'over a year' => [['block', '203.0.113.7', '--minutes=' . (IpBlocking::MAX_MINUTES + 1)], "ip: --minutes must be a whole number from 1 to 525600\n"];
        yield 'empty reason' => [['block', '203.0.113.7', '--reason= '], "ip: --reason must be 1 to 200 characters of UTF-8 text with no control characters\n"];
        yield 'reason with a newline' => [['block', '203.0.113.7', "--reason=a\nb"], "ip: --reason must be 1 to 200 characters of UTF-8 text with no control characters\n"];
        yield 'long reason' => [['block', '203.0.113.7', '--reason=' . str_repeat('r', 201)], "ip: --reason must be 1 to 200 characters of UTF-8 text with no control characters\n"];
    }

    /**
     * @param list<string> $args
     */
    #[DataProvider('badArguments')]
    public function testBadArgumentsPrintUsageAndChangeNothing(array $args, string $problem): void
    {
        self::assertSame([2, '', $problem . self::USAGE], $this->runCli($args));
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM ip_blocks')->fetchColumn());
    }

    public function testBlockAndUnblockWriteEventsWithoutTheIpOrTheReasonText(): void
    {
        $this->runCli(['block', '203.0.113.7']);
        $this->runCli(['block', '203.0.113.8', '--minutes=15', '--reason=from 198.51.100.4']);
        $this->runCli(['unblock', '203.0.113.7']);
        $this->runCli(['unblock', '203.0.113.7']);

        $events = $this->pdo->query('SELECT * FROM events ORDER BY id')->fetchAll();
        self::assertSame(
            [
                ['ip.block', 'api', 'ok', '{"source":"manual","reason":"default","duration_minutes":null}'],
                ['ip.block', 'api', 'ok', '{"source":"manual","reason":"given","duration_minutes":15}'],
                ['ip.unblock', 'api', 'ok', '{"source":"manual","was_active":true}'],
                ['ip.unblock', 'api', 'ok', '{"source":null,"was_active":false}'],
            ],
            array_map(static fn (array $row): array => [$row['event_type'], $row['source'], $row['status'], $row['meta']], $events),
        );
        $all = json_encode($events, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        foreach (['203.0.113', '198.51.100', '::ffff:'] as $form) {
            self::assertStringNotContainsString($form, $all);
        }
    }

    public function testAFailedEventWriteDoesNotFailTheCommand(): void
    {
        $this->pdo->exec("CREATE TRIGGER events_fail BEFORE INSERT ON events BEGIN SELECT RAISE(ABORT, 'no'); END");

        [$exit, $out, $err] = $this->runCli(['block', '203.0.113.7']);
        self::assertSame(0, $exit);
        self::assertSame("ip: blocked 203.0.113.7 (manual) until unblocked\n", $out);
        self::assertSame("fx-installments metrics: event ip.block not recorded (PDOException)\n", $err);

        [$exit, $out, $err] = $this->runCli(['unblock', '203.0.113.7']);
        self::assertSame(0, $exit);
        self::assertStringStartsWith('ip: unblocked 203.0.113.7', $out);
        self::assertSame("fx-installments metrics: event ip.unblock not recorded (PDOException)\n", $err);
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM events')->fetchColumn());
    }

    public function testAMissingDatabaseFileIsRefusedWithoutCreatingIt(): void
    {
        $path = TestEnvironment::tempDir('ip-cli-missing') . '/missing.db';

        foreach ([['list'], ['block', '203.0.113.7'], ['unblock', '203.0.113.7']] as $args) {
            self::assertSame([1, '', "ip: failed: no database file (run php bin/migrate.php first)\n"], $this->runCli($args, $path));
        }
        self::assertFileDoesNotExist($path);
    }

    public function testADatabaseBehindTheCodeIsRefused(): void
    {
        $path = TestEnvironment::tempDir('ip-cli-empty') . '/empty.db';
        touch($path);

        foreach ([['list'], ['block', '203.0.113.7'], ['unblock', '203.0.113.7']] as $args) {
            self::assertSame([1, '', "ip: failed: FxInstallments\\Db\\SchemaOutOfDate\n"], $this->runCli($args, $path));
        }
        self::assertSame([], TestEnvironment::connect($path)->query('SELECT name FROM sqlite_master')->fetchAll());
    }

    /**
     * @param list<string> $args
     * @return array{int, string, string}
     */
    private function runCli(array $args, ?string $path = null): array
    {
        $dir = TestEnvironment::tempDir('ip-cli');
        $env = getenv();
        $env['APP_ENV'] = 'test';
        $env['DB_DSN'] = 'sqlite:' . ($path ?? $this->path);
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__, 3) . '/bin/ip.php', ...$args],
            [0 => ['pipe', 'r'], 1 => ['file', $dir . '/out', 'w'], 2 => ['file', $dir . '/err', 'w']],
            $pipes,
            $dir,
            $env,
        );
        self::assertIsResource($process, 'could not start bin/ip.php');
        fclose($pipes[0]);
        $exit = proc_close($process);

        return [$exit, (string) file_get_contents($dir . '/out'), (string) file_get_contents($dir . '/err')];
    }
}
