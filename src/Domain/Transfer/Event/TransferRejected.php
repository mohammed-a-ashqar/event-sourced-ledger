<?php

declare(strict_types=1);

namespace Ledger\Domain\Transfer\Event;

/**
 * The source account refused the debit; nothing moved.
 */
final readonly class TransferRejected extends TransferOutcome
{
    public static function eventType(): string
    {
        return 'transfer.rejected';
    }
}
