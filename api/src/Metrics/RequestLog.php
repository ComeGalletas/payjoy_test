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
 * - The path never carries the query string: anything from the first `?` or `#` is dropped.
 * - Path and method are stored as pure ASCII: every byte outside 0x21-0x7E is percent-encoded
 *   (`é` is `%C3%A9`, `\xFF` is `%FF`, a space is `%20`), then the value is cut to 200 (path)
 *   or 16 (method) characters without splitting a `%XX` escape or the escapes of one UTF-8
 *   character. So a stored path is always
 *   valid UTF-8 (portable to Postgres TEXT) and "200 characters" holds. The Kernel's `error`
 *   event uses the same rules (storedPath(), storedMethod()).
 * - A failed write never fails the request (the INV-07 policy of Metrics): every Throwable is
 *   caught and a short note naming the exception class only goes to the fallback logger.
 *
 * Raw IPs are personal data: they live only here, in `ip_blocks` and in `rate_limits` (the
 * per-IP minute counts), and `--prune` deletes them: `requests` rows older than
 * `requests.retention_days`, `rate_limits` windows older than a day, and ended blocks one
 * window after their end (Db\Retention).
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
     * The count behind `block.error_responses`: requests from $ip with `ts >= $since` that were
     * not blocked and got a 4xx other than 429. (Rate-limited minutes come from rate_limits,
     * RateLimiter::rateLimitedWindows().) Throws on a database failure.
     *
     * Cost: one range read of the `requests_ip_ts` index over the IP's rows since $since, which
     * visits its other rows in that range too (2xx, 429, 5xx). IpBlocking only calls this on a
     * non-429 4xx, and fewer than `block.error_responses` such responses fit in a window before
     * the IP is blocked, so the work per window is at most `block.error_responses` scans of the
     * window: linear in the IP's traffic, not quadratic. Blocked requests lie before the block's
     * end, which `$since` excludes.
     */
    public function otherClientErrorsSince(string $ip, int $since): int
    {
        $select = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM requests WHERE ip = ? AND ts >= ? AND blocked = 0'
            . ' AND status >= 400 AND status < 500 AND status <> 429',
        );
        $select->execute([substr($ip, 0, self::MAX_IP), $since]);

        return (int) $select->fetchColumn();
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
     * cut back before a `%XX` escape it would split (the value's own escapes included), and
     * then back before the escapes of a UTF-8 character it would leave incomplete.
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

        return substr($encoded, 0, self::withoutPartialCharacter($encoded, $cut));
    }

    /**
     * Where to cut so the escapes before $cut don't end in an incomplete UTF-8 sequence: walks
     * back over at most four trailing `%XX` escapes to the sequence's lead byte and, if the
     * sequence has fewer bytes than the lead announces, cuts before the lead.
     */
    private static function withoutPartialCharacter(string $encoded, int $cut): int
    {
        $at = $cut;
        $continuations = 0;
        while ($continuations < 4 && $at >= 3 && preg_match('/^%[0-9A-Fa-f]{2}$/', substr($encoded, $at - 3, 3)) === 1) {
            $byte = (int) hexdec(substr($encoded, $at - 2, 2));
            $at -= 3;
            if (($byte & 0xC0) !== 0x80) {
                $length = match (true) {
                    $byte >= 0xF8 => 1, // never a UTF-8 lead byte: stands alone
                    $byte >= 0xF0 => 4,
                    $byte >= 0xE0 => 3,
                    $byte >= 0xC0 => 2,
                    default => 1,
                };

                return $continuations + 1 < $length ? $at : $cut;
            }
            $continuations++;
        }

        return $cut;
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
