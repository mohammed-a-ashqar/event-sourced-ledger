<?php

declare(strict_types=1);

namespace Ledger\Domain\Shared;

/**
 * An ISO 4217 currency supported by the ledger, with its number of minor units.
 */
final readonly class Currency
{
    /**
     * Supported currencies and their ISO 4217 exponent (digits after the decimal point).
     */
    private const MINOR_UNITS = [
        'AED' => 2, 'AUD' => 2, 'BHD' => 3, 'CAD' => 2, 'CHF' => 2, 'CNY' => 2, 'CZK' => 2,
        'DKK' => 2, 'EGP' => 2, 'EUR' => 2, 'GBP' => 2, 'HKD' => 2, 'ILS' => 2, 'INR' => 2,
        'JOD' => 3, 'JPY' => 0, 'KRW' => 0, 'KWD' => 3, 'MAD' => 2, 'NOK' => 2, 'NZD' => 2,
        'OMR' => 3, 'PLN' => 2, 'QAR' => 2, 'SAR' => 2, 'SEK' => 2, 'SGD' => 2, 'TND' => 3,
        'TRY' => 2, 'USD' => 2, 'ZAR' => 2,
    ];

    private function __construct(public string $code) {}

    public static function of(string $code): self
    {
        if (! array_key_exists($code, self::MINOR_UNITS)) {
            throw UnsupportedCurrency::code($code);
        }

        return new self($code);
    }

    /**
     * @return list<string>
     */
    public static function supportedCodes(): array
    {
        return array_keys(self::MINOR_UNITS);
    }

    public function minorUnits(): int
    {
        return self::MINOR_UNITS[$this->code];
    }

    public function equals(self $other): bool
    {
        return $this->code === $other->code;
    }

    public function __toString(): string
    {
        return $this->code;
    }
}
