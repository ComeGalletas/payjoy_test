<?php

declare(strict_types=1);

/*
 * The bot in a terminal (docs/PLAN.md §7), channel `cli`:
 *
 *     php api/bin/bot.php                               interactive
 *     printf '200\nbrazil\n3\n' | php api/bin/bot.php   scripted
 *
 * It runs the flow engine in-process on the configured database (DB_DSN, default
 * api/var/app.db), so its sessions and events land next to the HTTP ones; the conversion uses
 * the rate cache and the real upstream client like `/convert`. Refuses to run while the
 * database is behind the code or its SQLite file is missing, which it never creates (run
 * php api/bin/migrate.php first). Exit codes: 0 the session completed, 1 it was abandoned, the
 * input ended first, or something failed (stderr names the exception class only, or gives the
 * fixed missing-file text), 2 usage.
 *
 * Text is read as UTF-8: on a Windows console, run `chcp 65001` first for accented input.
 */

use FxInstallments\App;
use FxInstallments\Bootstrap;
use FxInstallments\Db\DatabaseFileMissing;
use FxInstallments\Flow\BotCli;

$apiRoot = dirname(__DIR__);
require $apiRoot . '/vendor/autoload.php';

if (count($argv) !== 1) {
    fwrite(STDERR, "usage: php api/bin/bot.php\n");
    exit(2);
}

try {
    Bootstrap::init($apiRoot);
    $app = App::fromEnvironment($apiRoot);
    $app->db->pdo();
    exit((new BotCli($app->flowEngine()))->run(STDIN, STDOUT, stream_isatty(STDIN)));
} catch (DatabaseFileMissing $e) {
    fwrite(STDERR, 'bot: failed: ' . $e->getMessage() . "\n");
    exit(1);
} catch (Throwable $e) {
    fwrite(STDERR, 'bot: failed: ' . $e::class . "\n");
    exit(1);
}
