<?php

declare(strict_types=1);

namespace Ledger\Domain\Transfer\Exception;

use Ledger\Domain\Shared\BusinessRuleViolation;
use Ledger\Domain\Transfer\TransferId;

final class InvalidTransfer extends BusinessRuleViolation
{
    public static function sameAccount(): self
    {
        return new self('Source and destination accounts must differ.');
    }

    public static function conflictingRetry(TransferId $id): self
    {
        return new self(sprintf('Transfer %s already exists with different details.', $id));
    }

    public function errorCode(): string
    {
        return 'invalid_transfer';
    }
}
