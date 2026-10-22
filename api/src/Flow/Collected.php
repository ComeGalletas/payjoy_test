<?php

declare(strict_types=1);

namespace FxInstallments\Flow;

use FxInstallments\Convert\CurrencyCountryMap;
use JsonException;

/**
 * What a session has collected so far: only validated values, never what the user typed.
 * Stored as JSON in `sessions.collected` with these keys, each present only once collected:
 *
 *     {"amount_usd_cents": 20000, "currency": "BRL", "country": "Brazil", "installments": 3}
 *
 * `country` is the canonical English name, set only when the user typed a country (§5);
 * `amount_usd_cents` is exact integer cents (ADR 0017). fromJson() refuses anything else, so a
 * damaged row is never read as a different answer.
 */
final readonly class Collected
{
    private const KEYS = ['amount_usd_cents', 'currency', 'country', 'installments'];

    public function __construct(
        public ?int $amountUsdCents = null,
        public ?string $currency = null,
        public ?string $country = null,
        public ?int $installments = null,
    ) {
    }

    /**
     * The stored form back, or null when it isn't exactly what toJson() writes.
     */
    public static function fromJson(string $json): ?self
    {
        try {
            $data = json_decode($json, true, 2, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
        if (!is_array($data) || ($data !== [] && array_is_list($data)) || array_diff(array_keys($data), self::KEYS) !== []) {
            return null;
        }

        $cents = $data['amount_usd_cents'] ?? null;
        $currency = $data['currency'] ?? null;
        $country = $data['country'] ?? null;
        $installments = $data['installments'] ?? null;
        if (($cents !== null && (!is_int($cents) || $cents < 1))
            || ($currency !== null && (!is_string($currency) || preg_match('/^[A-Z]{3}$/D', $currency) !== 1))
            || ($country !== null && (!is_string($country) || $currency === null || CurrencyCountryMap::currencyOf($country) !== $currency))
            || ($installments !== null && (!is_int($installments) || $installments < 1))) {
            return null;
        }

        return new self($cents, $currency, $country, $installments);
    }

    public function toJson(): string
    {
        $fields = $this->eventFields();

        return $fields === [] ? '{}' : json_encode($fields, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Whether the field an input step expects (`amount`, `currency`, `installments`) is set.
     */
    public function has(string $expecting): bool
    {
        return match ($expecting) {
            'amount' => $this->amountUsdCents !== null,
            'currency' => $this->currency !== null,
            'installments' => $this->installments !== null,
            default => false,
        };
    }

    /**
     * A copy without that field (the currency goes with its country).
     */
    public function without(string $expecting): self
    {
        return match ($expecting) {
            'amount' => new self(null, $this->currency, $this->country, $this->installments),
            'currency' => new self($this->amountUsdCents, null, null, $this->installments),
            'installments' => new self($this->amountUsdCents, $this->currency, $this->country, null),
            default => $this,
        };
    }

    public function withAmount(int $cents): self
    {
        return new self($cents, $this->currency, $this->country, $this->installments);
    }

    public function withCurrency(string $currency, ?string $country): self
    {
        return new self($this->amountUsdCents, $currency, $country, $this->installments);
    }

    public function withInstallments(int $installments): self
    {
        return new self($this->amountUsdCents, $this->currency, $this->country, $installments);
    }

    /**
     * The `events` columns this fills (§5): only the collected ones.
     *
     * @return array<string, int|string>
     */
    public function eventFields(): array
    {
        return array_filter([
            'amount_usd_cents' => $this->amountUsdCents,
            'currency' => $this->currency,
            'country' => $this->country,
            'installments' => $this->installments,
        ], static fn (int|string|null $value): bool => $value !== null);
    }
}
