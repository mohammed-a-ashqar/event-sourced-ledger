<?php

declare(strict_types=1);

namespace Ledger\Domain\Ledger;

use Ledger\Domain\Account\Event\MoneyDeposited;
use Ledger\Domain\Account\Event\MoneyWithdrawn;
use Ledger\Domain\Account\Event\TransferCredited;
use Ledger\Domain\Account\Event\TransferDebited;
use Ledger\Domain\Account\Event\TransferDebitReversed;
use Ledger\EventSourcing\DomainEvent;

/**
 * Translates money-moving account events into balanced journal entries.
 *
 * A transfer goes through a clearing account, so each saga step is
 * balanced on its own and money "in flight" is visible on the books:
 *
 *   debit leg      Dr customer:source        Cr transfer-clearing
 *   credit leg     Dr transfer-clearing      Cr customer:destination
 *   reversal       Dr transfer-clearing      Cr customer:source
 */
final class PostingRules
{
    public static function journalEntryFor(DomainEvent $event, string $entryId): ?JournalEntry
    {
        $postings = match (true) {
            $event instanceof MoneyDeposited => [
                Posting::debit(LedgerAccount::cash($event->amount->currency), $event->amount),
                Posting::credit(LedgerAccount::customer($event->accountId), $event->amount),
            ],
            $event instanceof MoneyWithdrawn => [
                Posting::debit(LedgerAccount::customer($event->accountId), $event->amount),
                Posting::credit(LedgerAccount::cash($event->amount->currency), $event->amount),
            ],
            $event instanceof TransferDebited => [
                Posting::debit(LedgerAccount::customer($event->accountId), $event->amount),
                Posting::credit(LedgerAccount::transferClearing($event->amount->currency), $event->amount),
            ],
            $event instanceof TransferCredited,
            $event instanceof TransferDebitReversed => [
                Posting::debit(LedgerAccount::transferClearing($event->amount->currency), $event->amount),
                Posting::credit(LedgerAccount::customer($event->accountId), $event->amount),
            ],
            default => null,
        };

        return $postings === null ? null : JournalEntry::balanced($entryId, $postings);
    }
}
