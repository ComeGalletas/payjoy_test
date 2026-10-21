<?php

declare(strict_types=1);

namespace FxInstallments\Metrics;

use Closure;
use FxInstallments\Db\Connection;
use PDO;
use Throwable;

/**
 * The request log (ADR 0016): one `requests` row per incoming request, blocked ones included,
 * with the raw client IP, method, path, final status, latency and whether it was blocked.
 * Business events stay in `events` (Metrics).
 *
 * - The path never carries the query string: anything from the first `?` or `#` is dropped.
 * - Path and method are stored as pure ASCII: every byte outside 0x21-0x7E is percent-encoded
 *   (`é` is `%C3%A9`, `\xFF` is `%FF`, a space is `%20`), then the value is cut to 200 (path)
 *   or 16 (method) characters without splitting a `%XX` escape. So a stored path is always
 *   valid UTF-8 (portable to Postgres TEXT) and "200 characters" holds. The Kernel's `error`
 *   event uses the same rules (storedPath(), storedMethod()).
 * - A failed write never fails the request (the INV-07 policy of Metrics): every Throwable is
 *   caught and a short note naming the exception class only goes to the fallback logger.
 *
 * Raw IPs are personal data: they live only here and in `ip_blocks`, and `--prune` deletes
 * rows older than `requests.retention_days`.
 */
final class RequestLog
{
    public const MAX_PATH = 200;
    public const MAX_METHOD = 16;
    /** The one IP length bound (requests.ip and ip_blocks.ip); longer than any textual IPv6 address (45). */
    public const MAX_IP = 64;

    /** @var Closure(string): void */
    private readonly Closure $fallbackLog;

    /**
     * @param (Closure(string): void)|null $fallbackLog where failed writes are noted; defaults to error_log()
     */
    public function __construct(private readonly Connection $db, ?Closure $fallbackLog = null)
    {
        $this->fallbackLog = $fallbackLog ?? static function (string $message): void {
            error_log($message);
        };
    }

    /**
     * Writes one row. Returns true when it was written; never throws.
     */
    public function record(int $ts, string $ip, string $method, string $path, int $status, int $latencyMs, bool $blocked): bool
    {
        try {
            $this->db->pdo()
                ->prepare('INSERT INTO requests (ts, ip, method, path, status, latency_ms, blocked) VALUES (?, ?, ?, ?, ?, ?, ?)')
                ->execute([
                    $ts,
                    substr($ip, 0, self::MAX_IP),
                    self::storedMethod($method),
                    self::storedPath($path),
                    $status,
                    max(0, $latencyMs),
                    $blocked ? 1 : 0,
                ]);

            return true;
        } catch (Throwable $e) {
            $this->noteFailure($e);

            return false;
        }
    }

    /**
     * The counts behind automatic blocking, over the requests from $ip with `ts >= $since` that
     * were not blocked (blocked requests, 403 IP_BLOCKED, never count):
     *
     * - rate-limited minutes: the number of distinct fixed one-minute UTC windows (`ts / 60`,
     *   RateLimiter's `window_start`) with at least one 429;
     * - other client errors: the number of 4xx responses other than 429.
     *
     * Integer `ts / 60` is integer division on SQLite and Postgres alike (ADR 0010). Throws on
     * a database failure.
     *
     * @return array{int, int} [rate-limited minutes, other 4xx responses]
     */
    public function clientErrorCountsSince(string $ip, int $since): array
    {
        $select = $this->db->pdo()->prepare(
            'SELECT COUNT(DISTINCT CASE WHEN status = 429 THEN ts / 60 END) AS rate_limited_minutes,'
            . ' SUM(CASE WHEN status <> 429 THEN 1 ELSE 0 END) AS other_client_errors'
            . ' FROM requests WHERE ip = ? AND ts >= ? AND blocked = 0 AND status >= 400 AND status < 500',
        );
        $select->execute([substr($ip, 0, self::MAX_IP), $since]);
        $row = $select->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? [(int) $row['rate_limited_minutes'], (int) $row['other_client_errors']] : [0, 0];
    }

    /**
     * The path as stored: no query string or fragment, ASCII only, at most MAX_PATH characters.
     */
    public static function storedPath(string $path): string
    {
        return self::asciiCut(substr($path, 0, strcspn($path, '?#')), self::MAX_PATH);
    }

    /**
     * The method as stored: ASCII only, at most MAX_METHOD characters.
     */
    public static function storedMethod(string $method): string
    {
        return self::asciiCut($method, self::MAX_METHOD);
    }

    /**
     * Percent-encodes every byte outside 0x21-0x7E, then cuts to $max characters, moving the
     * cut back before a `%XX` escape it would split (the value's own escapes included).
     */
    private static function asciiCut(string $value, int $max): string
    {
        $encoded = (string) preg_replace_callback(
            '/[^\x21-\x7E]/',
            static fn (array $byte): string => sprintf('%%%02X', ord($byte[0])),
            $value,
        );
        if (strlen($encoded) <= $max) {
            return $encoded;
        }
        $cut = $max;
        foreach ([1, 2] as $back) {
            $at = $max - $back;
            if ($encoded[$at] === '%' && preg_match('/^%[0-9A-Fa-f]{2}/', substr($encoded, $at, 3)) === 1) {
                $cut = $at;
                break;
            }
        }

        return substr($encoded, 0, $cut);
    }

    private function noteFailure(Throwable $e): void
    {
        try {
            ($this->fallbackLog)(sprintf('fx-installments request log: request not recorded (%s)', $e::class));
        } catch (Throwable) {
            // The fallback log must not fail the request either.
        }
    }
}
