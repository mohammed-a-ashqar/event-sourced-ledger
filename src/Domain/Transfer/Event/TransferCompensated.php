<?php

declare(strict_types=1);

namespace Ledger\Domain\Transfer\Event;

/**
 * The destination refused the credit and the source debit was reversed.
 */
final readonly class TransferCompensated extends TransferOutcome
{
    public static function eventType(): string
    {
        return 'transfer.compensated';
    }
}
