<?php

declare(strict_types=1);

namespace Ledger\Infrastructure\Projection;

use Illuminate\Database\ConnectionInterface;
use Ledger\Domain\Transfer\Event\TransferCompensated;
use Ledger\Domain\Transfer\Event\TransferCompleted;
use Ledger\Domain\Transfer\Event\TransferCreditFailed;
use Ledger\Domain\Transfer\Event\TransferInitiated;
use Ledger\Domain\Transfer\Event\TransferRejected;
use Ledger\Domain\Transfer\Event\TransferSourceDebited;
use Ledger\Domain\Transfer\TransferId;
use Ledger\Domain\Transfer\TransferStatus;
use Ledger\EventSourcing\EventEnvelope;
use Ledger\Infrastructure\Database\Timestamp;

/**
 * Current state of every transfer, including the ones still in flight.
 */
final readonly class TransfersProjector implements Projector
{
    private const TABLE = 'transfers';

    public function __construct(private ConnectionInterface $db) {}

    public function name(): string
    {
        return 'transfers';
    }

    public function project(EventEnvelope $envelope): void
    {
        $event = $envelope->event;

        match (true) {
            $event instanceof TransferInitiated => $this->db->table(self::TABLE)->insert([
                'transfer_id' => $event->transferId->value,
                'status' => TransferStatus::Initiated->value,
                'initiated_at' => Timestamp::toColumn($envelope->metadata->occurredAt),
                'updated_at' => Timestamp::toColumn($envelope->metadata->occurredAt),
            ] + $event->terms->toPayload()),
            $event instanceof TransferSourceDebited => $this->moveTo($envelope, $event->transferId, TransferStatus::SourceDebited),
            $event instanceof TransferCompleted => $this->moveTo($envelope, $event->transferId, TransferStatus::Completed),
            $event instanceof TransferRejected => $this->moveTo($envelope, $event->transferId, TransferStatus::Rejected, $event->reason),
            $event instanceof TransferCreditFailed => $this->moveTo($envelope, $event->transferId, TransferStatus::CreditFailed, $event->reason),
            $event instanceof TransferCompensated => $this->moveTo($envelope, $event->transferId, TransferStatus::Compensated, $event->reason),
            default => null,
        };
    }

    public function reset(): void
    {
        $this->db->table(self::TABLE)->delete();
    }

    private function moveTo(EventEnvelope $envelope, TransferId $id, TransferStatus $status, ?string $reason = null): void
    {
        $this->db->table(self::TABLE)->where('transfer_id', $id->value)->update([
            'status' => $status->value,
            'failure_reason' => $reason === null ? null : mb_substr($reason, 0, 255),
            'updated_at' => Timestamp::toColumn($envelope->metadata->occurredAt),
        ]);
    }
}
