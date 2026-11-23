<?php

declare(strict_types=1);

namespace Ledger\Domain\Account\Event;

/**
 * The destination account received the transfer amount (second saga step).
 */
final readonly class TransferCredited extends TransferLegEvent
{
    public static function eventType(): string
    {
        return 'account.transfer_credited';
    }
}
