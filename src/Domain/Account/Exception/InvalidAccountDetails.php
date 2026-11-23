<?php

declare(strict_types=1);

namespace Ledger\Domain\Account\Exception;

use Ledger\Domain\Shared\BusinessRuleViolation;

final class InvalidAccountDetails extends BusinessRuleViolation
{
    public static function ownerName(): self
    {
        return new self('Owner name must contain between 1 and 120 characters.');
    }

    public static function freezeReason(): self
    {
        return new self('A reason (1 to 255 characters) is required to freeze an account.');
    }

    public function errorCode(): string
    {
        return 'invalid_account_details';
    }
}
