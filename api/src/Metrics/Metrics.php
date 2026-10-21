<?php

declare(strict_types=1);

namespace FxInstallments\Metrics;

use Closure;
use FxInstallments\Db\Connection;
use FxInstallments\Db\Database;
use InvalidArgumentException;
use Throwable;

/**
 * The single event writer (docs/PLAN.md §3, §5). Every event is one append-only row in
 * `events`: `ts` is a UTC epoch integer, `meta` is JSON text, amounts are stored unrounded
 * (floats are bound with Database::floatParam, never PDO's 14-digit default).
 *
 * A failed write never fails the caller (INV-07): every Throwable is caught and a short note
 * goes to the fallback logger (PHP's error_log by default). The note names the event type and
 * the exception class only, never the exception message or event values, so no secret or user
 * input can reach the log through it.
 */
final class Metrics
{
    public const SOURCES = ['api', 'bot', 'upstream', 'llm'];
    public const CHANNELS = ['static', 'cli', 'typebot'];

    private const COLUMNS = [
        'source', 'channel', 'session_id', 'step', 'currency', 'country', 'amount_usd',
        'installments', 'status', 'error_code', 'latency_ms', 'meta',
    ];

    private const JSON_FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION;

    /** @var Closure(): int */
    private readonly Closure $clock;

    /** @var Closure(string): void */
    private readonly Closure $fallbackLog;

    /**
     * @param (Closure(): int)|null $clock UTC epoch seconds; defaults to time()
     * @param (Closure(string): void)|null $fallbackLog where failed writes are noted; defaults to error_log()
     */
    public function __construct(
        private readonly Connection $db,
        ?Closure $clock = null,
        ?Closure $fallbackLog = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
        $this->fallbackLog = $fallbackLog ?? static function (string $message): void {
            error_log($message);
        };
    }

    /**
     * Records one event. $fields may hold any `events` column except id, ts and event_type:
     * source (default 'api'), channel, session_id, step, currency, country, amount_usd,
     * installments, status (default 'error' when error_code is set, else 'ok'), error_code,
     * latency_ms, and meta (an array, stored as JSON).
     *
     * Returns true when the row was written. It never throws.
     *
     * @param array<string, mixed> $fields
     */
    public function record(string $eventType, array $fields = []): bool
    {
        try {
            $row = $this->row($eventType, $fields);
            $columns = array_keys($row);
            $sql = sprintf(
                'INSERT INTO events (%s) VALUES (%s)',
                implode(', ', $columns),
                implode(', ', array_fill(0, count($columns), '?')),
            );
            $values = array_map(
                static fn (mixed $value): mixed => is_float($value) ? Database::floatParam($value) : $value,
                array_values($row),
            );
            $this->db->pdo()->prepare($sql)->execute($values);

            return true;
        } catch (Throwable $e) {
            $this->noteFailure($eventType, $e);

            return false;
        }
    }

    /**
     * Records an `error` event for an error code from ErrorCodes.
     *
     * @param array<string, mixed> $fields
     */
    public function error(string $errorCode, array $fields = []): bool
    {
        return $this->record('error', ['error_code' => $errorCode] + $fields);
    }

    /**
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    private function row(string $eventType, array $fields): array
    {
        $unknown = array_diff(array_keys($fields), self::COLUMNS);
        if (preg_match('/^[a-z][a-z0-9_]*(\.[a-z0-9_]+)*$/', $eventType) !== 1 || $unknown !== []) {
            throw new InvalidArgumentException('Invalid event type or unknown event fields');
        }

        $errorCode = $fields['error_code'] ?? null;
        if ($errorCode !== null && (!is_string($errorCode) || !ErrorCodes::isKnown($errorCode))) {
            throw new InvalidArgumentException('Unknown error code');
        }
        $source = $fields['source'] ?? 'api';
        $channel = $fields['channel'] ?? null;
        $status = $fields['status'] ?? ($errorCode === null ? 'ok' : 'error');
        if (!in_array($source, self::SOURCES, true)
            || ($channel !== null && !in_array($channel, self::CHANNELS, true))
            || !in_array($status, ['ok', 'error'], true)) {
            throw new InvalidArgumentException('Invalid source, channel or status');
        }

        $meta = $fields['meta'] ?? null;
        if ($meta !== null && !is_array($meta)) {
            throw new InvalidArgumentException('meta must be an array');
        }

        return [
            'ts' => ($this->clock)(),
            'event_type' => $eventType,
            'source' => $source,
            'status' => $status,
            'meta' => $meta === null ? null : json_encode($meta, self::JSON_FLAGS),
        ] + array_intersect_key($fields, array_flip(self::COLUMNS));
    }

    private function noteFailure(string $eventType, Throwable $e): void
    {
        try {
            $safeType = preg_replace('/[^A-Za-z0-9_.]/', '?', substr($eventType, 0, 64));
            ($this->fallbackLog)(sprintf('fx-installments metrics: event %s not recorded (%s)', $safeType, $e::class));
        } catch (Throwable) {
            // The fallback log must not fail the request either.
        }
    }
}
