<?php

declare(strict_types=1);

/*
 * PHPUnit bootstrap.
 *
 * Every value PHP code could read from the environment is replaced in all three places
 * ($_ENV, $_SERVER, putenv) before the app's own Bootstrap runs:
 *
 * - APP_ENV=test, so Bootstrap skips .env entirely and no test ever sees the real key;
 * - a fake EXCHANGERATE_API_KEY (INV-06);
 * - an empty ADMIN_TOKEN and TRUSTED_PROXY (tests that need them set their own);
 * - DATA_ROOT and BACKUP_ROOT pointing at fresh temp dirs (GR-001);
 * - DB_DSN pointing at a temp SQLite file, never api/var/app.db.
 *
 * Then the no-network guard replaces the network stream wrappers and Guzzle's real transport
 * handlers (INV-06, ADR 0018), so a test that builds a Guzzle client without an injected
 * MockHandler fails. The Composer scripts also start PHPUnit with -d allow_url_fopen=0 and
 * -d disable_functions=curl_exec,curl_multi_exec.
 */

use FxInstallments\Bootstrap;
use FxInstallments\Tests\Support\NetworkGuard;
use FxInstallments\Tests\Support\TestEnvironment;

require dirname(__DIR__) . '/vendor/autoload.php';

(static function (): void {
    $set = static function (string $name, string $value): void {
        putenv("{$name}={$value}");
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    };

    $set('APP_ENV', 'test');
    $set('EXCHANGERATE_API_KEY', 'test-fake-key-not-real');
    $set('ADMIN_TOKEN', '');
    $set('TRUSTED_PROXY', '');
    $set('DATA_ROOT', TestEnvironment::tempDir('data-root'));
    $set('BACKUP_ROOT', TestEnvironment::tempDir('backup-root'));
    $set('DB_DSN', 'sqlite:' . TestEnvironment::tempDir('suite-db') . '/suite.db');
    $set('DB_USER', '');
    $set('DB_PASS', '');
})();

Bootstrap::init(dirname(__DIR__));
NetworkGuard::install();
