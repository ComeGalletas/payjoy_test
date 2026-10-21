<?php

declare(strict_types=1);

namespace FxInstallments\Rates;

use FxInstallments\Convert\ScaledRate;
use FxInstallments\Db\Connection;
use PDO;
use Throwable;

/**
 * Reads and writes `rate_cache` (docs/PLAN.md §5): one row per currency, all written by one
 * refresh. No upstream calls; RateCache decides when to refresh.
 */
final class RateStore
{
    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * The stored table; null when `rate_cache` is empty. Rows with an invalid code or scaled
     * rate are skipped.
     */
    public function table(): ?RateTable
    {
        $rows = $this->db->pdo()
            ->query('SELECT currency, rate_scaled, last_update_unix, next_update_unix, fetched_at FROM rate_cache')
            ->fetchAll(PDO::FETCH_ASSOC);
        $rates = [];
        $last = $next = $fetched = PHP_INT_MAX;
        foreach ($rows as $row) {
            $code = $row['currency'] ?? null;
            $scaled = $row['rate_scaled'] ?? null;
            if (is_string($scaled) && preg_match('/^[1-9][0-9]{0,18}$/D', $scaled) === 1) {
                $scaled = (int) $scaled; // drivers that return BIGINT as text
            }
            if (!is_string($code) || preg_match('/^[A-Z]{3}$/D', $code) !== 1
                || !is_int($scaled) || $scaled < 1 || $scaled >= ScaledRate::MAX_SCALED) {
                continue;
            }
            $rates[$code] = $scaled;
            $last = min($last, (int) $row['last_update_unix']);
            $next = min($next, (int) $row['next_update_unix']);
            $fetched = min($fetched, (int) $row['fetched_at']);
        }
        if ($rates === []) {
            return null;
        }
        ksort($rates, SORT_STRING);

        return new RateTable($rates, $last, $next, $fetched);
    }

    /**
     * The stored currency codes, without loading the rates.
     *
     * @return list<string>
     */
    public function codes(): array
    {
        $codes = [];
        foreach ($this->db->pdo()->query('SELECT currency FROM rate_cache')->fetchAll(PDO::FETCH_COLUMN) as $code) {
            if (is_string($code) && preg_match('/^[A-Z]{3}$/D', $code) === 1) {
                $codes[] = $code;
            }
        }
        sort($codes, SORT_STRING);

        return $codes;
    }

    /**
     * Replaces every row with $table, in one transaction: a failure leaves the old table.
     */
    public function replace(RateTable $table): void
    {
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $pdo->exec('DELETE FROM rate_cache');
            $insert = $pdo->prepare(
                'INSERT INTO rate_cache (currency, rate_scaled, last_update_unix, next_update_unix, fetched_at) '
                . 'VALUES (?, ?, ?, ?, ?)',
            );
            foreach ($table->rates as $code => $scaled) {
                $insert->execute([$code, $scaled, $table->lastUpdateUnix, $table->nextUpdateUnix, $table->fetchedAt]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
