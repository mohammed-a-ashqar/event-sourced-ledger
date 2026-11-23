<?php

declare(strict_types=1);

namespace Ledger\Domain\Transfer;

/**
 * States of the transfer saga.
 *
 *   initiated --debit ok--> source_debited --credit ok--> completed
 *       |                        |
 *   debit refused          credit refused
 *       v                        v
 *   rejected               credit_failed --refund--> compensated
 */
enum TransferStatus: string
{
    case Initiated = 'initiated';
    case SourceDebited = 'source_debited';
    case Completed = 'completed';
    case Rejected = 'rejected';
    case CreditFailed = 'credit_failed';
    case Compensated = 'compensated';

    public function isFinal(): bool
    {
        return match ($this) {
            self::Completed, self::Rejected, self::Compensated => true,
            self::Initiated, self::SourceDebited, self::CreditFailed => false,
        };
    }
}
