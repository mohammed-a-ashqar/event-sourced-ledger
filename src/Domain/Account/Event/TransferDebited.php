<?php

declare(strict_types=1);

namespace Ledger\Domain\Account\Event;

/**
 * The source account paid out the transfer amount (first saga step).
 */
final readonly class TransferDebited extends TransferLegEvent
{
    public static function eventType(): string
    {
        return 'account.transfer_debited';
    }
}
