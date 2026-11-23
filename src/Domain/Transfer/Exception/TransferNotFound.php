<?php

declare(strict_types=1);

namespace Ledger\Domain\Transfer\Exception;

use Ledger\Domain\Shared\BusinessRuleViolation;
use Ledger\Domain\Transfer\TransferId;

final class TransferNotFound extends BusinessRuleViolation
{
    public static function withId(TransferId $id): self
    {
        return new self(sprintf('Transfer %s does not exist.', $id));
    }

    public function errorCode(): string
    {
        return 'transfer_not_found';
    }
}
