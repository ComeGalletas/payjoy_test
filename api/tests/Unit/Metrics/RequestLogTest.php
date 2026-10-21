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
    public function testThePathHasNoQueryStringAndIsCutTo200Bytes(string $path, string $stored): void
    {
        self::assertSame($stored, RequestLog::storedPath($path));

        $this->requestLog()->record(self::NOW, '203.0.113.7', 'GET', $path, 404, 1, false);
        self::assertSame($stored, $this->rows()[0]['path']);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function paths(): iterable
    {
        yield 'plain' => ['/convert', '/convert'];
        yield 'query string' => ['/convert?amount=200&token=secret', '/convert'];
        yield 'empty query' => ['/convert?', '/convert'];
        yield 'fragment' => ['/a#b?c', '/a'];
        yield 'only a query' => ['?x=1', ''];
        yield 'exactly 200' => ['/' . str_repeat('a', 199), '/' . str_repeat('a', 199)];
        yield '201 cut to 200' => ['/' . str_repeat('a', 200), '/' . str_repeat('a', 199)];
        yield 'long with query' => ['/' . str_repeat('b', 300) . '?q=1', '/' . str_repeat('b', 199)];
        yield 'query after 200 bytes' => ['/' . str_repeat('c', 250) . '?secret', '/' . str_repeat('c', 199)];
    }

    public function testTheMethodAndIpAreBounded(): void
    {
        $this->requestLog()->record(self::NOW, str_repeat('9', 100), str_repeat('X', 40), '/', 404, 1, false);

        $row = $this->rows()[0];
        self::assertSame(str_repeat('9', RequestLog::MAX_IP), $row['ip']);
        self::assertSame(str_repeat('X', RequestLog::MAX_METHOD), $row['method']);
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

    public function testClientErrorCountsCoverUnblocked4xxSinceTheGivenTime(): void
    {
        $log = $this->requestLog();
        $ip = '203.0.113.7';
        foreach ([
            [self::NOW - 1, 429, false],   // before $since
            [self::NOW, 429, false],
            [self::NOW, 404, false],
            [self::NOW + 5, 429, false],
            [self::NOW + 5, 400, false],
            [self::NOW + 5, 499, false],
            [self::NOW + 5, 403, true],    // blocked: never counted
            [self::NOW + 5, 500, false],   // 5xx: not a client error
            [self::NOW + 5, 503, false],
            [self::NOW + 5, 200, false],
            [self::NOW + 5, 399, false],
        ] as [$ts, $status, $blocked]) {
            $log->record($ts, $ip, 'GET', '/', $status, 1, $blocked);
        }
        $log->record(self::NOW, '198.51.100.1', 'GET', '/', 429, 1, false);

        self::assertSame([2, 5], $log->clientErrorCountsSince($ip, self::NOW));
        self::assertSame([3, 6], $log->clientErrorCountsSince($ip, self::NOW - 1));
        self::assertSame([0, 0], $log->clientErrorCountsSince($ip, self::NOW + 6));
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
