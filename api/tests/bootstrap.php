<?php

declare(strict_types=1);

/*
 * PHPUnit bootstrap.
 *
 * Loads the Composer autoloader and replaces EXCHANGERATE_API_KEY with a fake value in every
 * place PHP code can read the environment from, so no test ever sees the real key
 * (docs/GUARDRAILS.md INV-06 groundwork).
 */

require dirname(__DIR__) . '/vendor/autoload.php';

(static function (): void {
    $fakeKey = 'test-fake-key-not-real';

    putenv('EXCHANGERATE_API_KEY=' . $fakeKey);
    $_ENV['EXCHANGERATE_API_KEY'] = $fakeKey;
    $_SERVER['EXCHANGERATE_API_KEY'] = $fakeKey;
})();
