<?php

declare(strict_types=1);

namespace Ledger\Domain\Shared;

final class InvalidAmount extends BusinessRuleViolation
{
    public static function mustBePositive(Money $amount): self
    {
        return new self(sprintf('Amount must be positive, got %s.', $amount));
    }

    public static function mustNotBeNegative(Money $amount): self
    {
        return new self(sprintf('Amount cannot be negative, got %s.', $amount));
    }

    public static function notADecimal(string $value, Currency $currency): self
    {
        return new self(sprintf(
            '"%s" is not a valid %s amount (at most %d decimal places).',
            $value,
            $currency,
            $currency->minorUnits(),
        ));
    }

    public static function overflow(): self
    {
        return new self('Amount exceeds the supported range.');
    }

    public function errorCode(): string
    {
        return 'invalid_amount';
    }
}
