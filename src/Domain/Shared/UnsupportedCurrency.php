<?php

declare(strict_types=1);

namespace Ledger\Domain\Shared;

final class UnsupportedCurrency extends BusinessRuleViolation
{
    public static function code(string $code): self
    {
        return new self(sprintf('Currency "%s" is not a supported ISO 4217 code.', $code));
    }

    public function errorCode(): string
    {
        return 'unsupported_currency';
    }
}
