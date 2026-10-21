<?php

declare(strict_types=1);

namespace FxInstallments\Tests\Support;

use PHPUnit\Framework\Attributes\After;

/**
 * Sets environment variables for one test ($_ENV, $_SERVER and putenv) and restores them after.
 */
trait OverridesEnvironment
{
    /** @var array<string, array{mixed, mixed, string|false}> */
    private array $savedEnvironment = [];

    /**
     * @param string|null $value null removes the variable
     */
    protected function setEnvironment(string $name, ?string $value): void
    {
        if (!array_key_exists($name, $this->savedEnvironment)) {
            $this->savedEnvironment[$name] = [
                $_ENV[$name] ?? null,
                $_SERVER[$name] ?? null,
                getenv($name),
            ];
        }
        self::applyEnvironment($name, $value, $value, $value ?? false);
    }

    #[After]
    protected function restoreEnvironment(): void
    {
        foreach ($this->savedEnvironment as $name => [$env, $server, $process]) {
            self::applyEnvironment($name, $env, $server, $process);
        }
        $this->savedEnvironment = [];
    }

    private static function applyEnvironment(string $name, mixed $env, mixed $server, string|false $process): void
    {
        if ($env === null) {
            unset($_ENV[$name]);
        } else {
            $_ENV[$name] = $env;
        }
        if ($server === null) {
            unset($_SERVER[$name]);
        } else {
            $_SERVER[$name] = $server;
        }
        putenv($process === false ? $name : "{$name}={$process}");
    }
}
