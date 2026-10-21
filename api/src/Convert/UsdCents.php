<?php

declare(strict_types=1);

namespace FxInstallments\Convert;

use InvalidArgumentException;

/**
 * USD amounts as exact integer cents (ADR 0017, CONTEXT "cents"). The conversion from the
 * canonical amount string uses integer arithmetic only, never a float, so `0.29` is 29 cents
 * and not 28.999...
 *
 * Only the canonical form `^\d{1,7}(\.\d{1,2})?$` is accepted. Range rules (> 0, at most
 * `amount.max`) belong to the amount validator (P1-03), not here; `0` converts to 0 cents.
 */
final class UsdCents
{
    public const PATTERN = '/^(\d{1,7})(?:\.(\d{1,2}))?$/D';

    /**
     * `200.50` -> 20050, `0.01` -> 1, `9999999.99` -> 999999999, `200.5` -> 20050.
     *
     * @throws InvalidArgumentException for anything that isn't a canonical amount string
     */
    public static function fromCanonical(string $amount): int
    {
        if (preg_match(self::PATTERN, $amount, $match) !== 1) {
            throw new InvalidArgumentException('Not a canonical amount string');
        }
        $fraction = str_pad($match[2] ?? '', 2, '0');

        // At most 7 + 2 digits: both parts are small exact integers.
        return (int) $match[1] * 100 + (int) $fraction;
    }

    /**
     * 20050 -> `200.50`: the canonical string with exactly two decimals.
     */
    public static function toCanonical(int $cents): string
    {
        if ($cents < 0) {
            throw new InvalidArgumentException('Cents must not be negative');
        }

        return intdiv($cents, 100) . '.' . str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }
}
