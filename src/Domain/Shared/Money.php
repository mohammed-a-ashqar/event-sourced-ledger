<?php

declare(strict_types=1);

namespace Ledger\Domain\Shared;

/**
 * An amount of money in integer minor units (cents, fils, yen...).
 *
 * Floats never appear: arithmetic is integer-only and overflow-checked,
 * and decimal strings are parsed digit by digit.
 */
final readonly class Money
{
    private function __construct(
        public int $amount,
        public Currency $currency,
    ) {}

    public static function of(int $minorUnits, Currency|string $currency): self
    {
        return new self($minorUnits, $currency instanceof Currency ? $currency : Currency::of($currency));
    }

    public static function zero(Currency $currency): self
    {
        return new self(0, $currency);
    }

    /**
     * Parses "12.5", "-0.75" or "1000" without ever going through a float.
     */
    public static function fromDecimal(string $value, Currency $currency): self
    {
        $exponent = $currency->minorUnits();
        $pattern = $exponent === 0 ? '/^(-?)(\d+)$/' : sprintf('/^(-?)(\d+)(?:\.(\d{1,%d}))?$/', $exponent);

        if (preg_match($pattern, $value, $parts) !== 1) {
            throw InvalidAmount::notADecimal($value, $currency);
        }

        $digits = ltrim($parts[2].str_pad($parts[3] ?? '', $exponent, '0'), '0');

        if ($digits === '') {
            return self::zero($currency);
        }

        if (strlen($digits) > 18) {
            throw InvalidAmount::overflow();
        }

        $minor = (int) $digits;

        return new self($parts[1] === '-' ? -$minor : $minor, $currency);
    }

    public function add(self $other): self
    {
        $this->assertSameCurrency($other);

        if (($other->amount > 0 && $this->amount > PHP_INT_MAX - $other->amount)
            || ($other->amount < 0 && $this->amount < PHP_INT_MIN - $other->amount)) {
            throw InvalidAmount::overflow();
        }

        return new self($this->amount + $other->amount, $this->currency);
    }

    public function subtract(self $other): self
    {
        return $this->add($other->negate());
    }

    public function negate(): self
    {
        if ($this->amount === PHP_INT_MIN) {
            throw InvalidAmount::overflow();
        }

        return new self(-$this->amount, $this->currency);
    }

    public function isZero(): bool
    {
        return $this->amount === 0;
    }

    public function isPositive(): bool
    {
        return $this->amount > 0;
    }

    public function isNegative(): bool
    {
        return $this->amount < 0;
    }

    public function isLessThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->amount < $other->amount;
    }

    public function equals(self $other): bool
    {
        return $this->currency->equals($other->currency) && $this->amount === $other->amount;
    }

    public function assertSameCurrency(self $other): void
    {
        if (! $this->currency->equals($other->currency)) {
            throw CurrencyMismatch::between($this->currency, $other->currency);
        }
    }

    public function toDecimal(): string
    {
        $exponent = $this->currency->minorUnits();
        $digits = ltrim((string) $this->amount, '-');
        $sign = $this->amount < 0 ? '-' : '';

        if ($exponent === 0) {
            return $sign.$digits;
        }

        $digits = str_pad($digits, $exponent + 1, '0', STR_PAD_LEFT);

        return $sign.substr($digits, 0, -$exponent).'.'.substr($digits, -$exponent);
    }

    public function __toString(): string
    {
        return $this->toDecimal().' '.$this->currency;
    }
}
