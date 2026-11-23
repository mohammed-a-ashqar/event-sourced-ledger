<?php

declare(strict_types=1);

namespace Ledger\Domain\Account\Event;

/**
 * Compensation: the source account got the debited amount back because the
 * credit to the destination failed.
 */
final readonly class TransferDebitReversed extends TransferLegEvent
{
    public static function eventType(): string
    {
        return 'account.transfer_debit_reversed';
    }
}
