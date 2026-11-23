<?php

declare(strict_types=1);

namespace Ledger\Infrastructure\Projection;

use Illuminate\Database\ConnectionInterface;
use Ledger\Domain\Ledger\PostingRules;
use Ledger\EventSourcing\EventEnvelope;
use Ledger\Infrastructure\Database\Timestamp;

/**
 * The general journal: every money event as balanced double-entry lines.
 * The trial balance is a simple aggregate over this table.
 */
final readonly class JournalProjector implements Projector
{
    private const TABLE = 'journal_lines';

    public function __construct(private ConnectionInterface $db) {}

    public function name(): string
    {
        return 'journal';
    }

    public function project(EventEnvelope $envelope): void
    {
        $entry = PostingRules::journalEntryFor($envelope->event, $envelope->eventId());

        if ($entry === null) {
            return;
        }

        $rows = [];

        foreach ($entry->postings as $lineNumber => $posting) {
            $rows[] = [
                'entry_id' => $entry->entryId,
                'line_number' => $lineNumber + 1,
                'ledger_account' => $posting->account->code,
                'direction' => $posting->direction->value,
                'amount' => $posting->amount->amount,
                'currency' => $posting->amount->currency->code,
                'position' => $envelope->position,
                'occurred_at' => Timestamp::toColumn($envelope->metadata->occurredAt),
            ];
        }

        $this->db->table(self::TABLE)->insert($rows);
    }

    public function reset(): void
    {
        $this->db->table(self::TABLE)->delete();
    }
}
