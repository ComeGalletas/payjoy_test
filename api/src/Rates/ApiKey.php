<?php

declare(strict_types=1);

namespace FxInstallments\Rates;

use LogicException;
use WeakMap;

/**
 * The upstream API key, held so that no generic dump of an object shows it (INV-03).
 *
 * The object has no properties: the value lives in a private static WeakMap keyed by the
 * object. So var_export(), `(array)` casts, get_object_vars() and json_encode() see nothing,
 * var_dump() and print_r() show `[redacted]` (__debugInfo), and serialize() and clone throw.
 * Only ExchangeRateClient reads it, to build the request URL.
 */
final class ApiKey
{
    /** @var WeakMap<self, string>|null */
    private static ?WeakMap $values = null;

    public function __construct(#[\SensitiveParameter] string $value)
    {
        self::$values ??= new WeakMap();
        self::$values[$this] = $value;
    }

    /**
     * The key, for the request URL only.
     */
    public function reveal(): string
    {
        return self::$values[$this] ?? throw new LogicException('API key missing');
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['value' => '[redacted]'];
    }

    /**
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        throw new LogicException('An API key is never serialized');
    }

    /**
     * @param array<string, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        throw new LogicException('An API key is never unserialized');
    }

    public function __clone()
    {
        throw new LogicException('An API key is never cloned');
    }

    public static function __set_state(array $properties): never
    {
        throw new LogicException('An API key is never exported');
    }
}
