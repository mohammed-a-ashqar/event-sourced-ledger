<?php

declare(strict_types=1);

namespace Ledger\Domain\Account\Exception;

use Ledger\Domain\Account\AccountId;
use Ledger\Domain\Shared\BusinessRuleViolation;

final class AccountIsFrozen extends BusinessRuleViolation
{
    public static function withId(AccountId $id): self
    {
        return new self(sprintf('Account %s is frozen.', $id));
    }

    public function errorCode(): string
    {
        return 'account_frozen';
    }
}
