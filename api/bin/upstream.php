<?php

declare(strict_types=1);

/*
 * Upstream breaker and rate cache, for operators (docs/PLAN.md §3b).
 *
 *     php bin/upstream.php status   print the breaker state and the stored rate table's times
 *     php bin/upstream.php reset    clear the breaker (after fixing the key, or to retry early);
 *                                   records an `upstream.breaker_reset` event
 *
 * Never calls upstream and never prints the API key (only whether one is set). Refuses to run
 * while the database is behind the code (run bin/migrate.php first). Exit codes: 0 done,
 * 1 failed, 2 usage.
 */

use FxInstallments\Bootstrap;
use FxInstallments\Db\Connection;
use FxInstallments\Db\Database;
use FxInstallments\Db\Migrator;
use FxInstallments\Env;
use FxInstallments\Metrics\Metrics;
use FxInstallments\Rates\BreakerState;
use FxInstallments\Rates\RateStore;
use FxInstallments\Rates\UpstreamBreaker;

$apiRoot = dirname(__DIR__);
require $apiRoot . '/vendor/autoload.php';

$args = array_slice($argv, 1);
$mode = match ($args) {
    ['status'] => 'status',
    ['reset'] => 'reset',
    default => null,
};
if ($mode === null) {
    fwrite(STDERR, "usage: php bin/upstream.php status | reset\n");
    exit(2);
}

$time = static fn (?int $unix): string => $unix === null ? 'never' : gmdate('Y-m-d\TH:i:s\Z', $unix);
$describe = static function (?BreakerState $state, int $now) use ($time): string {
    if ($state === null) {
        return 'closed';
    }
    $open = $state->isOpenAt($now) ? 'open' : 'expired';

    return sprintf(
        '%s (%s, %s) since %s until %s%s',
        $open,
        $state->kind,
        $state->errorCode,
        $time($state->since),
        $state->until === null ? 'reset' : $time($state->until),
        $state->kind === BreakerState::BACKOFF ? sprintf(', %d consecutive failure(s)', $state->failures) : '',
    );
};

try {
    Bootstrap::init($apiRoot);
    $pdo = Database::fromEnvironment($apiRoot);
    (new Migrator($pdo))->assertCurrent();
    $db = Connection::wrap($pdo);
    $breaker = new UpstreamBreaker($db);
    $now = time();

    if ($mode === 'reset') {
        $cleared = $breaker->reset();
        // Fixed fields only: never the key.
        (new Metrics($db))->record('upstream.breaker_reset', [
            'source' => 'upstream',
            'meta' => [
                'cleared' => $cleared?->kind,
                'was_open' => $cleared !== null && $cleared->isOpenAt($now),
                'by' => 'cli',
            ],
        ]);
        fwrite(STDOUT, $cleared === null
            ? "upstream: breaker was already closed\n"
            : 'upstream: breaker reset (was ' . $describe($cleared, $now) . ")\n");
        exit(0);
    }

    fwrite(STDOUT, 'breaker: ' . $describe($breaker->state(), $now) . "\n");
    $table = (new RateStore($db))->table();
    fwrite(STDOUT, $table === null
        ? "rates: cache empty (the fallback currency list is the allow-list)\n"
        : sprintf(
            "rates: %d currencies, rate_as_of %s, next_update %s, fetched_at %s\n",
            count($table->rates),
            $time($table->lastUpdateUnix),
            $time($table->nextUpdateUnix),
            $time($table->fetchedAt),
        ));
    fwrite(STDOUT, 'api key: ' . (Env::nonEmpty('EXCHANGERATE_API_KEY') === null ? 'not set' : 'set') . "\n");
    exit(0);
} catch (Throwable $e) {
    // The class only: a message could name the database path or other local details.
    fwrite(STDERR, sprintf("upstream: failed: %s\n", $e::class));
    exit(1);
}
