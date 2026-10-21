<?php

declare(strict_types=1);

namespace FxInstallments\Tests\Unit\Metrics;

use ArrayObject;
use FxInstallments\Db\Connection;
use FxInstallments\Metrics\RequestLog;
use FxInstallments\Tests\Support\TestEnvironment;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RequestLogTest extends TestCase
{
    private const NOW = 1_790_000_000;

    private PDO $pdo;

    /** @var ArrayObject<int, string> */
    private ArrayObject $log;

    protected function setUp(): void
    {
        $this->pdo = TestEnvironment::connect(TestEnvironment::freshDatabasePath());
        $this->log = new ArrayObject();
    }

    public function testARowHoldsEveryColumn(): void
    {
        self::assertTrue($this->requestLog()->record(self::NOW, '203.0.113.7', 'GET', '/convert', 200, 12, false));
        self::assertTrue($this->requestLog()->record(self::NOW + 1, '2001:db8::1', 'POST', '/bot/message', 403, 0, true));

        self::assertSame([
            ['ts' => self::NOW, 'ip' => '203.0.113.7', 'method' => 'GET', 'path' => '/convert', 'status' => 200, 'latency_ms' => 12, 'blocked' => 0],
            ['ts' => self::NOW + 1, 'ip' => '2001:db8::1', 'method' => 'POST', 'path' => '/bot/message', 'status' => 403, 'latency_ms' => 0, 'blocked' => 1],
        ], $this->rows());
        self::assertSame([], $this->log->getArrayCopy());
    }

    #[DataProvider('paths')]
    public function testThePathIsAsciiWithoutQueryStringAndAtMost200Characters(string $path, string $stored): void
    {
        self::assertSame($stored, RequestLog::storedPath($path));
        self::assertLessThanOrEqual(200, strlen($stored));
        self::assertMatchesRegularExpression('/^[\x21-\x7E]*$/', $stored);

        $this->requestLog()->record(self::NOW, '203.0.113.7', 'GET', $path, 404, 1, false);
        self::assertSame($stored, $this->rows()[0]['path']);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function paths(): iterable
    {
        $a199 = '/' . str_repeat('a', 199);
        yield 'plain' => ['/convert', '/convert'];
        yield 'query string' => ['/convert?amount=200&token=secret', '/convert'];
        yield 'empty query' => ['/convert?', '/convert'];
        yield 'fragment' => ['/a#b?c', '/a'];
        yield 'only a query' => ['?x=1', ''];
        yield 'a 200-character ASCII path stays unchanged' => [$a199, $a199];
        yield '201 cut to 200' => ['/' . str_repeat('a', 200), $a199];
        yield 'long with query' => ['/' . str_repeat('b', 300) . '?q=1', '/' . str_repeat('b', 199)];
        yield 'query after 200 bytes' => ['/' . str_repeat('c', 250) . '?secret', '/' . str_repeat('c', 199)];
        yield 'é is percent-encoded' => ['/caf' . "\u{e9}", '/caf%C3%A9'];
        yield 'an invalid byte is percent-encoded' => ["/x\xFFy", '/x%FFy'];
        yield 'a space is percent-encoded' => ['/a b', '/a%20b'];
        yield 'control bytes and DEL are percent-encoded' => ["/\t\x00\x7F", '/%09%00%7F'];
        yield 'existing escapes are kept' => ['/caf%C3%A9', '/caf%C3%A9'];
        // 197 ASCII + "é" (%C3%A9): the cut at 200 would land inside "%C3" or "%A9".
        yield 'cut would split the 1st escape after its %' => ['/' . str_repeat('d', 198) . "\u{e9}", '/' . str_repeat('d', 198)];
        yield 'cut would split the 1st escape after %C' => ['/' . str_repeat('d', 197) . "\u{e9}", '/' . str_repeat('d', 197)];
        yield 'cut would split the 2nd escape' => ['/' . str_repeat('d', 194) . "\u{e9}", '/' . str_repeat('d', 194) . '%C3'];
        yield 'cut right after an escape keeps it' => ['/' . str_repeat('d', 193) . "\u{e9}", '/' . str_repeat('d', 193) . '%C3%A9'];
        yield 'a client escape at the cut is not split either' => ['/' . str_repeat('d', 198) . '%41', '/' . str_repeat('d', 198)];
        yield 'a lone % at the cut is kept' => ['/' . str_repeat('d', 198) . '%zz', '/' . str_repeat('d', 198) . '%'];
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function methods(): iterable
    {
        yield 'GET' => ['GET', 'GET'];
        yield 'exactly 16' => [str_repeat('X', 16), str_repeat('X', 16)];
        yield 'cut to 16' => [str_repeat('X', 40), str_repeat('X', 16)];
        yield 'non-ASCII is percent-encoded' => ["G\u{c9}T", 'G%C3%89T'];
        yield 'a space is percent-encoded' => ['GE T', 'GE%20T'];
        yield 'the cut never splits an escape' => [str_repeat('M', 14) . "\xFF", str_repeat('M', 14)];
    }

    #[DataProvider('methods')]
    public function testTheMethodIsAsciiAndAtMost16Characters(string $method, string $stored): void
    {
        self::assertSame($stored, RequestLog::storedMethod($method));

        $this->requestLog()->record(self::NOW, '203.0.113.7', $method, '/', 404, 1, false);
        self::assertSame($stored, $this->rows()[0]['method']);
    }

    public function testStoredValuesAreValidUtf8AndEncodeAsJson(): void
    {
        $path = RequestLog::storedPath('/' . str_repeat("\u{e9}", 150));

        self::assertTrue(mb_check_encoding($path, 'UTF-8'));
        self::assertSame('"' . $path . '"', json_encode($path, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        self::assertSame('/' . str_repeat('%C3%A9', 33), $path);
    }

    public function testTheIpIsBounded(): void
    {
        $this->requestLog()->record(self::NOW, str_repeat('9', 100), 'GET', '/', 404, 1, false);

        self::assertSame(str_repeat('9', RequestLog::MAX_IP), $this->rows()[0]['ip']);
    }

    public function testANegativeLatencyIsStoredAsZero(): void
    {
        $this->requestLog()->record(self::NOW, '203.0.113.7', 'GET', '/', 200, -5, false);

        self::assertSame(0, $this->rows()[0]['latency_ms']);
    }

    public function testAFailedWriteIsSwallowedAndNotedWithTheClassOnly(): void
    {
        $path = TestEnvironment::freshDatabasePath();
        $log = new RequestLog(Connection::wrap(TestEnvironment::connectReadOnly($path)), $this->noter());

        self::assertFalse($log->record(self::NOW, '203.0.113.7', 'GET', '/secret-path?token=abc', 200, 1, false));

        self::assertSame(0, (int) TestEnvironment::connect($path)->query('SELECT COUNT(*) FROM requests')->fetchColumn());
        self::assertSame(['fx-installments request log: request not recorded (PDOException)'], $this->log->getArrayCopy());
    }

    public function testADatabaseThatCantBeOpenedIsSwallowedToo(): void
    {
        $log = new RequestLog(new Connection(static fn (): PDO => throw new RuntimeException('cannot open C:\\secret')), $this->noter());

        self::assertFalse($log->record(self::NOW, '203.0.113.7', 'GET', '/', 200, 1, false));
        self::assertSame(['fx-installments request log: request not recorded (RuntimeException)'], $this->log->getArrayCopy());
    }

    public function testAFailingFallbackLoggerIsSwallowedToo(): void
    {
        $path = TestEnvironment::freshDatabasePath();
        $log = new RequestLog(Connection::wrap(TestEnvironment::connectReadOnly($path)), static function (): void {
            throw new RuntimeException('log down');
        });

        self::assertFalse($log->record(self::NOW, '203.0.113.7', 'GET', '/', 200, 1, false));
    }

    public function testClientErrorCountsAreRateLimitedMinutesAndOther4xx(): void
    {
        $log = $this->requestLog();
        $ip = '203.0.113.7';
        $minute = intdiv(self::NOW, 60) * 60;
        foreach ([
            [$minute - 1, 429, false],     // the minute before, and before $since below
            [$minute, 429, false],         // minute 0, three times: counts once
            [$minute + 30, 429, false],
            [$minute + 59, 429, false],
            [$minute + 60, 429, false],    // minute 1
            [$minute + 60, 429, true],     // blocked: never counted
            [$minute + 180, 429, true],    // a minute with only a blocked 429: not counted
            [$minute, 404, false],
            [$minute + 5, 400, false],
            [$minute + 5, 499, false],
            [$minute + 5, 403, true],      // blocked: never counted
            [$minute + 5, 500, false],     // 5xx: not a client error
            [$minute + 5, 503, false],
            [$minute + 5, 200, false],
            [$minute + 5, 399, false],
        ] as [$ts, $status, $blocked]) {
            $log->record($ts, $ip, 'GET', '/', $status, 1, $blocked);
        }
        $log->record($minute + 120, '198.51.100.1', 'GET', '/', 429, 1, false);

        self::assertSame([2, 3], $log->clientErrorCountsSince($ip, $minute));
        self::assertSame([3, 3], $log->clientErrorCountsSince($ip, $minute - 1));
        self::assertSame([2, 0], $log->clientErrorCountsSince($ip, $minute + 6));
        self::assertSame([1, 0], $log->clientErrorCountsSince($ip, $minute + 60));
        self::assertSame([0, 0], $log->clientErrorCountsSince($ip, $minute + 61));
        self::assertSame([0, 0], $log->clientErrorCountsSince('192.0.2.1', 0));
    }

    private function requestLog(): RequestLog
    {
        return new RequestLog(Connection::wrap($this->pdo), $this->noter());
    }

    private function noter(): \Closure
    {
        $log = $this->log;

        return static function (string $line) use ($log): void {
            $log[] = $line;
        };
    }

    /**
     * @return list<array<string, int|string>>
     */
    private function rows(): array
    {
        return array_map(
            static fn (array $row): array => [
                'ts' => (int) $row['ts'],
                'ip' => $row['ip'],
                'method' => $row['method'],
                'path' => $row['path'],
                'status' => (int) $row['status'],
                'latency_ms' => (int) $row['latency_ms'],
                'blocked' => (int) $row['blocked'],
            ],
            $this->pdo->query('SELECT ts, ip, method, path, status, latency_ms, blocked FROM requests ORDER BY id')->fetchAll(),
        );
    }
}
