<?php

declare(strict_types=1);

namespace FxInstallments\Config;

use InvalidArgumentException;

/**
 * Read-only config (docs/PLAN.md §6). Values come from Config/defaults.php; tests may pass
 * overrides for keys that exist there. There is no write path until Phase 3.
 *
 * Each getter checks the key exists and has the expected type, so a typo or a wrong default
 * fails loudly instead of returning a surprising value.
 *
 * `amount.max` is checked when the config loads, since no reader can work around it: it must be
 * an integer (L-04 cleanup B, owner decision 2026-10-22) of at least 1 (cleanup A), or every
 * amount would fail `over_max`. Every other key's type is reported only when the key is read,
 * so a key no request uses can't break the others.
 */
final class ConfigRepository
{
    /** @var array<string, mixed> */
    private readonly array $values;

    /**
     * @param array<string, mixed> $overrides only keys present in defaults.php
     */
    public function __construct(array $overrides = [])
    {
        $defaults = self::defaults();
        $unknown = array_diff_key($overrides, $defaults);
        if ($unknown !== []) {
            throw new InvalidArgumentException('Unknown config key(s): ' . implode(', ', array_keys($unknown)));
        }
        $this->values = $overrides + $defaults;

        $amountMax = $this->values['amount.max'] ?? null;
        if (!is_int($amountMax)) {
            throw new InvalidArgumentException('amount.max must be an integer');
        }
        if ($amountMax < 1) {
            throw new InvalidArgumentException('amount.max must be at least 1');
        }
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        /** @var array<string, mixed> $defaults */
        $defaults = require __DIR__ . '/defaults.php';

        return $defaults;
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->values);
    }

    public function int(string $key): int
    {
        $value = $this->value($key);
        if (!is_int($value)) {
            throw self::wrongType($key, 'int');
        }

        return $value;
    }

    public function float(string $key): float
    {
        $value = $this->value($key);
        if (!is_float($value) && !is_int($value)) {
            throw self::wrongType($key, 'float');
        }

        return (float) $value;
    }

    public function bool(string $key): bool
    {
        $value = $this->value($key);
        if (!is_bool($value)) {
            throw self::wrongType($key, 'bool');
        }

        return $value;
    }

    public function string(string $key): string
    {
        $value = $this->value($key);
        if (!is_string($value)) {
            throw self::wrongType($key, 'string');
        }

        return $value;
    }

    /**
     * @return list<string>
     */
    public function stringList(string $key): array
    {
        $value = $this->value($key);
        if (!is_array($value) || !array_is_list($value)) {
            throw self::wrongType($key, 'list of strings');
        }
        foreach ($value as $item) {
            if (!is_string($item)) {
                throw self::wrongType($key, 'list of strings');
            }
        }

        /** @var list<string> $value */
        return $value;
    }

    private function value(string $key): mixed
    {
        if (!array_key_exists($key, $this->values)) {
            throw new InvalidArgumentException("Unknown config key: {$key}");
        }

        return $this->values[$key];
    }

    private static function wrongType(string $key, string $expected): InvalidArgumentException
    {
        return new InvalidArgumentException("Config key {$key} is not a {$expected}");
    }
}
