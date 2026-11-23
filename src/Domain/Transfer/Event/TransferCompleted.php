<?php

declare(strict_types=1);

namespace Ledger\Domain\Transfer\Event;

final readonly class TransferCompleted extends TransferOutcome
{
    public static function eventType(): string
    {
        return 'transfer.completed';
    }
}
