<?php

declare(strict_types=1);

namespace FxInstallments\Tests\Unit\Http;

use FxInstallments\Config\ConfigRepository;
use FxInstallments\Db\Connection;
use FxInstallments\Http\IpBlock;
use FxInstallments\Http\IpBlocking;
use FxInstallments\Http\IpBlocks;
use FxInstallments\Metrics\RequestLog;
use FxInstallments\Tests\Support\TestEnvironment;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class IpBlockingTest extends TestCase
{
    private const NOW = 1_790_000_000;
    private const IP = '203.0.113.7';
    private const WINDOW = 600;      // block.window_minutes = 10
    private const DURATION = 3600;   // block.duration_minutes = 60

    private PDO $pdo;
    private RequestLog $requests;
    private IpBlocks $blocks;

    protected function setUp(): void
    {
        $this->pdo = TestEnvironment::connect(TestEnvironment::freshDatabasePath());
        $db = Connection::wrap($this->pdo);
        $this->requests = new RequestLog($db, static function (): void {
        });
        $this->blocks = new IpBlocks($db);
    }

    public function testTheDefaultsComeFromConfig(): void
    {
        $blocking = IpBlocking::fromConfig($this->blocks, $this->requests, new ConfigRepository(), null);

        self::assertSame([10, 5, 100, 60], [$blocking->windowMinutes, $blocking->rateLimitHits, $blocking->errorResponses, $blocking->durationMinutes]);
    }

    public function testTheRateLimitHitThresholdIsReachedNotExceeded(): void
    {
        $blocking = $this->blocking(rateLimitHits: 3);

        $this->log(self::NOW, 429, 2);
        self::assertNull($blocking->afterResponse(self::IP, 429, self::NOW, null), 'two 429s are under three');

        $this->log(self::NOW, 429);
        $block = $blocking->afterResponse(self::IP, 429, self::NOW, null);
        self::assertEquals(new IpBlock(self::IP, 'rate_limit_hits', 'auto', self::NOW, self::NOW + self::DURATION), $block);
        self::assertEquals($block, $this->blocks->find(self::IP));
    }

    public function testTheErrorResponseThresholdCountsEvery4xxIncluding429s(): void
    {
        $blocking = $this->blocking(rateLimitHits: 100, errorResponses: 5);

        foreach ([400, 401, 404, 429] as $status) {
            $this->log(self::NOW, $status);
        }
        self::assertNull($blocking->afterResponse(self::IP, 404, self::NOW, null));

        $this->log(self::NOW, 499);
        self::assertSame('error_responses', $blocking->afterResponse(self::IP, 499, self::NOW, null)?->reason);
    }

    public function testRateLimitHitsWinWhenBothThresholdsAreReached(): void
    {
        $blocking = $this->blocking(rateLimitHits: 2, errorResponses: 2);
        $this->log(self::NOW, 429, 2);

        self::assertSame('rate_limit_hits', $blocking->afterResponse(self::IP, 429, self::NOW, null)?->reason);
    }

    public function testServerErrorsSuccessesAndBlockedRequestsNeverCount(): void
    {
        $blocking = $this->blocking(rateLimitHits: 1, errorResponses: 1);
        $this->log(self::NOW, 500, 10);
        $this->log(self::NOW, 503, 10);
        $this->log(self::NOW, 200, 10);
        $this->log(self::NOW, 403, 10, blocked: true);

        self::assertNull($blocking->afterResponse(self::IP, 404, self::NOW, null), 'nothing counted, the current 404 was not logged');
        self::assertSame([], $this->blockRows());
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function statusesThatNeverTriggerACount(): iterable
    {
        foreach ([200, 204, 302, 399, 500, 503] as $status) {
            yield (string) $status => [$status];
        }
    }

    #[DataProvider('statusesThatNeverTriggerACount')]
    public function testOnlyA4xxResponseIsChecked(int $status): void
    {
        $blocking = $this->blocking(rateLimitHits: 1, errorResponses: 1);
        $this->log(self::NOW, 429, 5);

        self::assertNull($blocking->afterResponse(self::IP, $status, self::NOW, null));
        self::assertSame([], $this->blockRows());
    }

    public function testTheWindowEdges(): void
    {
        $blocking = $this->blocking(rateLimitHits: 2);

        // Exactly window seconds old: out of the window.
        $this->log(self::NOW - self::WINDOW, 429);
        $this->log(self::NOW, 429);
        self::assertNull($blocking->afterResponse(self::IP, 429, self::NOW, null));

        // One second younger: in it.
        $this->log(self::NOW - self::WINDOW + 1, 429);
        self::assertNotNull($blocking->afterResponse(self::IP, 429, self::NOW, null));
    }

    public function testOnlyThisIpsRequestsCount(): void
    {
        $blocking = $this->blocking(rateLimitHits: 2);
        $this->log(self::NOW, 429, 5, ip: '198.51.100.1');
        $this->log(self::NOW, 429);

        self::assertNull($blocking->afterResponse(self::IP, 429, self::NOW, null));
    }

    public function testRequestsBeforeThePreviousBlockExpiredNeverCountAgain(): void
    {
        $blocking = $this->blocking(rateLimitHits: 3);
        $expiredAt = self::NOW - 60;
        $previous = new IpBlock(self::IP, 'rate_limit_hits', 'auto', $expiredAt - self::DURATION, $expiredAt);
        $this->log($expiredAt - 1, 429, 2);   // just before the block ended
        $this->log($expiredAt, 429);           // the block's last instant is already unblocked
        $this->log(self::NOW, 429);

        self::assertNull($blocking->afterResponse(self::IP, 429, self::NOW, $previous), 'only two 429s since the expiry');
        self::assertNotNull($blocking->afterResponse(self::IP, 429, self::NOW, null), 'four without the cutoff');
    }

    public function testAPermanentPreviousBlockDoesNotMoveTheWindow(): void
    {
        $blocking = $this->blocking(rateLimitHits: 2);
        $this->log(self::NOW - 10, 429, 2);

        self::assertNotNull($blocking->afterResponse(self::IP, 429, self::NOW, new IpBlock(self::IP, 'r', 'manual', 0, null)));
    }

    public function testANewThresholdOnAnActiveAutomaticBlockExtendsIt(): void
    {
        $blocking = $this->blocking(rateLimitHits: 1);
        $this->blocks->blockAutomatically(self::IP, 'rate_limit_hits', self::NOW - 600, self::NOW + 3000);
        $this->log(self::NOW, 429);

        self::assertSame(self::NOW + self::DURATION, $blocking->afterResponse(self::IP, 429, self::NOW, null)?->expiresAt);
    }

    /**
     * @return iterable<string, array{string, string|null}>
     */
    public static function exemptAddresses(): iterable
    {
        yield 'IPv4 loopback' => ['127.0.0.1', null];
        yield 'other 127/8 address' => ['127.8.9.10', null];
        yield 'IPv6 loopback' => ['::1', null];
        yield 'IPv4-mapped loopback' => ['::ffff:127.0.0.1', null];
        yield 'the trusted proxy' => ['10.0.0.5', '10.0.0.5'];
        yield 'empty' => ['', null];
        yield 'not an IP' => ['unknown', null];
    }

    #[DataProvider('exemptAddresses')]
    public function testLoopbackTheTrustedProxyAndNonIpsAreNeverBlockedAutomatically(string $ip, ?string $trustedProxy): void
    {
        $blocking = $this->blocking(rateLimitHits: 1, errorResponses: 1, trustedProxy: $trustedProxy);
        $this->log(self::NOW, 429, 3, ip: $ip);

        self::assertTrue($blocking->isExempt($ip));
        self::assertNull($blocking->afterResponse($ip, 429, self::NOW, null));
        self::assertSame([], $this->blockRows());
    }

    /**
     * @return iterable<string, array{string, string|null}>
     */
    public static function blockableAddresses(): iterable
    {
        yield 'public IPv4' => ['203.0.113.7', null];
        yield 'private IPv4' => ['10.0.0.6', '10.0.0.5'];
        yield 'public IPv6' => ['2001:db8::1', null];
        yield 'IPv4-mapped public' => ['::ffff:203.0.113.7', null];
        yield '128.0.0.1 is not loopback' => ['128.0.0.1', null];
        yield '::2 is not loopback' => ['::2', null];
    }

    #[DataProvider('blockableAddresses')]
    public function testOtherAddressesCanBeBlocked(string $ip, ?string $trustedProxy): void
    {
        $blocking = $this->blocking(rateLimitHits: 1, trustedProxy: $trustedProxy);
        $this->log(self::NOW, 429, 1, ip: $ip);

        self::assertFalse($blocking->isExempt($ip));
        self::assertNotNull($blocking->afterResponse($ip, 429, self::NOW, null));
    }

    /**
     * @return iterable<string, array{array<string, int>}>
     */
    public static function invalidSettings(): iterable
    {
        yield 'window 0' => [['block.window_minutes' => 0]];
        yield 'window over a year' => [['block.window_minutes' => IpBlocking::MAX_MINUTES + 1]];
        yield 'duration 0' => [['block.duration_minutes' => 0]];
        yield 'duration negative' => [['block.duration_minutes' => -60]];
        yield 'duration over a year' => [['block.duration_minutes' => IpBlocking::MAX_MINUTES + 1]];
        yield 'rate limit hits 0' => [['block.rate_limit_hits' => 0]];
        yield 'error responses 0' => [['block.error_responses' => 0]];
    }

    /**
     * @param array<string, int> $settings
     */
    #[DataProvider('invalidSettings')]
    public function testOutOfRangeSettingsFailLoudly(array $settings): void
    {
        $this->expectException(InvalidArgumentException::class);
        IpBlocking::fromConfig($this->blocks, $this->requests, new ConfigRepository($settings), null);
    }

    public function testTheLimitsThemselvesAreAccepted(): void
    {
        $blocking = IpBlocking::fromConfig($this->blocks, $this->requests, new ConfigRepository([
            'block.window_minutes' => IpBlocking::MAX_MINUTES,
            'block.duration_minutes' => 1,
            'block.rate_limit_hits' => 1,
            'block.error_responses' => 1,
        ]), null);

        self::assertSame(IpBlocking::MAX_MINUTES, $blocking->windowMinutes);
    }

    public function testANonIntegerSettingFailsLoudly(): void
    {
        $this->expectException(InvalidArgumentException::class);
        IpBlocking::fromConfig($this->blocks, $this->requests, new ConfigRepository(['block.window_minutes' => '10']), null);
    }

    private function blocking(int $rateLimitHits = 5, int $errorResponses = 100, ?string $trustedProxy = null): IpBlocking
    {
        return new IpBlocking($this->blocks, $this->requests, self::WINDOW / 60, $rateLimitHits, $errorResponses, self::DURATION / 60, $trustedProxy);
    }

    private function log(int $ts, int $status, int $times = 1, bool $blocked = false, string $ip = self::IP): void
    {
        for ($i = 0; $i < $times; $i++) {
            self::assertTrue($this->requests->record($ts, $ip, 'GET', '/x', $status, 1, $blocked));
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function blockRows(): array
    {
        return $this->pdo->query('SELECT * FROM ip_blocks')->fetchAll();
    }
}
