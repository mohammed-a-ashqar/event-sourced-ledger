<?php

declare(strict_types=1);

namespace Ledger\Domain\Transfer\Exception;

use Ledger\Domain\Shared\BusinessRuleViolation;
use Ledger\Domain\Transfer\TransferId;
use Ledger\Domain\Transfer\TransferStatus;

final class IllegalTransferTransition extends BusinessRuleViolation
{
    public static function from(TransferId $id, TransferStatus $current, string $action): self
    {
        return new self(sprintf('Transfer %s cannot %s while %s.', $id, $action, $current->value));
    }

    public function errorCode(): string
    {
        return 'illegal_transfer_transition';
    }
}
