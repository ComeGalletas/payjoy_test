<?php

declare(strict_types=1);

namespace FxInstallments\Metrics;

use Closure;
use FxInstallments\Db\Connection;
use Throwable;

/**
 * The request log (ADR 0016): one `requests` row per incoming request, blocked ones included,
 * with the raw client IP, method, path, final status, latency and whether it was blocked.
 * Business events stay in `events` (Metrics).
 *
 * - The path never carries the query string (anything from the first `?` or `#` is dropped)
 *   and is cut to 200 bytes; the method to 16. Both are byte cuts, like the `error` event's
 *   meta: request targets are ASCII (percent-encoded), so bytes are characters.
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
    /** Longer than any textual IPv6 address (45). */
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
                    substr($method, 0, self::MAX_METHOD),
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
     * The client-error counts behind automatic blocking: responses from $ip with `ts >= $since`
     * that were not blocked, as [429 responses, all 4xx responses (429s included)]. Blocked
     * requests (403 IP_BLOCKED) are never counted. Throws on a database failure.
     *
     * @return array{int, int}
     */
    public function clientErrorCountsSince(string $ip, int $since): array
    {
        $select = $this->db->pdo()->prepare(
            'SELECT SUM(CASE WHEN status = 429 THEN 1 ELSE 0 END) AS rate_limited, COUNT(*) AS client_errors'
            . ' FROM requests WHERE ip = ? AND ts >= ? AND blocked = 0 AND status >= 400 AND status < 500',
        );
        $select->execute([substr($ip, 0, self::MAX_IP), $since]);
        $row = $select->fetch(\PDO::FETCH_ASSOC);

        return is_array($row) ? [(int) $row['rate_limited'], (int) $row['client_errors']] : [0, 0];
    }

    /**
     * The path as stored: no query string or fragment, at most MAX_PATH bytes.
     */
    public static function storedPath(string $path): string
    {
        $cut = strcspn($path, '?#');

        return substr(substr($path, 0, $cut), 0, self::MAX_PATH);
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
