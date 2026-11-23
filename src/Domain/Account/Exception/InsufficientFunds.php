<?php

declare(strict_types=1);

namespace Ledger\Domain\Account\Exception;

use Ledger\Domain\Account\AccountId;
use Ledger\Domain\Shared\BusinessRuleViolation;
use Ledger\Domain\Shared\Money;

final class InsufficientFunds extends BusinessRuleViolation
{
    public static function toDebit(AccountId $id, Money $requested, Money $available): self
    {
        return new self(sprintf(
            'Account %s cannot be debited %s; only %s is available (balance plus overdraft).',
            $id,
            $requested,
            $available,
        ));
    }

    public function errorCode(): string
    {
        return 'insufficient_funds';
    }
}
