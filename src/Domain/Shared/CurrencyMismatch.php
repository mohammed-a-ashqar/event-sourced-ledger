<?php

declare(strict_types=1);

namespace Ledger\Domain\Shared;

final class CurrencyMismatch extends BusinessRuleViolation
{
    public static function between(Currency $expected, Currency $actual): self
    {
        return new self(sprintf('Expected an amount in %s, got %s.', $expected, $actual));
    }

    public function errorCode(): string
    {
        return 'currency_mismatch';
    }
}
