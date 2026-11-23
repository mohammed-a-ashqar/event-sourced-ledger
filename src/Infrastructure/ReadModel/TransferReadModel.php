<?php

declare(strict_types=1);

namespace Ledger\Infrastructure\ReadModel;

use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Ledger\Domain\Transfer\TransferStatus;
use Ledger\Infrastructure\Database\Row;
use Ledger\Infrastructure\Database\Timestamp;

/**
 * @phpstan-type TransferShape array{id: string, source_account_id: string, destination_account_id: string, amount: array{amount: int, currency: string, formatted: string}, status: string, failure_reason: string|null, initiated_at: string, updated_at: string}
 */
final readonly class TransferReadModel
{
    public function __construct(private ConnectionInterface $db) {}

    /**
     * @return TransferShape|null
     */
    public function find(string $transferId): ?array
    {
        $row = $this->db->table('transfers')->where('transfer_id', $transferId)->first();

        if (! is_object($row)) {
            return null;
        }

        return [
            'id' => Row::string($row, 'transfer_id'),
            'source_account_id' => Row::string($row, 'source_account_id'),
            'destination_account_id' => Row::string($row, 'destination_account_id'),
            'amount' => MoneyView::of(Row::int($row, 'amount'), Row::string($row, 'currency')),
            'status' => Row::string($row, 'status'),
            'failure_reason' => Row::nullableString($row, 'failure_reason'),
            'initiated_at' => Timestamp::toIso8601(Row::string($row, 'initiated_at')),
            'updated_at' => Timestamp::toIso8601(Row::string($row, 'updated_at')),
        ];
    }

    /**
     * Transfers that stopped mid-saga (e.g. the process died) and have not moved since $cutoff.
     *
     * @return list<string> transfer ids
     */
    public function stalledSince(DateTimeImmutable $cutoff): array
    {
        $unfinished = array_map(
            static fn (TransferStatus $status): string => $status->value,
            array_filter(TransferStatus::cases(), static fn (TransferStatus $status): bool => ! $status->isFinal()),
        );

        $ids = [];

        foreach ($this->db->table('transfers')
            ->whereIn('status', $unfinished)
            ->where('updated_at', '<', Timestamp::toColumn($cutoff))
            ->orderBy('updated_at')
            ->get(['transfer_id']) as $row) {
            $ids[] = Row::string($row, 'transfer_id');
        }

        return $ids;
    }
}
