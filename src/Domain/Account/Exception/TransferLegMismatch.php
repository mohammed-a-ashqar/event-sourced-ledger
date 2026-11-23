<?php

declare(strict_types=1);

namespace Ledger\Domain\Account\Exception;

use Ledger\Domain\Account\AccountId;
use Ledger\Domain\Shared\BusinessRuleViolation;
use Ledger\Domain\Transfer\TransferId;

final class TransferLegMismatch extends BusinessRuleViolation
{
    public static function notDebited(AccountId $accountId, TransferId $transferId): self
    {
        return new self(sprintf('Account %s was never debited for transfer %s, so there is nothing to reverse.', $accountId, $transferId));
    }

    public static function differentAmount(AccountId $accountId, TransferId $transferId): self
    {
        return new self(sprintf('Transfer %s was already applied to account %s with a different amount.', $transferId, $accountId));
    }

    public function errorCode(): string
    {
        return 'transfer_leg_mismatch';
    }
}
