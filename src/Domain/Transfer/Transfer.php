<?php

declare(strict_types=1);

namespace Ledger\Domain\Transfer;

use Ledger\Domain\Transfer\Event\TransferCompensated;
use Ledger\Domain\Transfer\Event\TransferCompleted;
use Ledger\Domain\Transfer\Event\TransferCreditFailed;
use Ledger\Domain\Transfer\Event\TransferInitiated;
use Ledger\Domain\Transfer\Event\TransferRejected;
use Ledger\Domain\Transfer\Event\TransferSourceDebited;
use Ledger\Domain\Transfer\Exception\IllegalTransferTransition;
use Ledger\EventSourcing\AggregateRoot;
use Ledger\EventSourcing\DomainEvent;
use LogicException;

/**
 * The state of one money transfer between two accounts.
 *
 * Accounts are separate aggregates (separate consistency boundaries), so a
 * transfer cannot be one atomic write. This aggregate records the progress
 * of the saga instead; the TransferProcessManager drives it.
 */
final class Transfer extends AggregateRoot
{
    private TransferId $id;

    private TransferTerms $terms;

    private TransferStatus $status;

    private ?string $failureReason = null;

    public static function initiate(TransferId $id, TransferTerms $terms): self
    {
        $transfer = new self;
        $transfer->recordThat(new TransferInitiated($id, $terms));

        return $transfer;
    }

    public function markSourceDebited(): void
    {
        $this->assertStatus(TransferStatus::Initiated, 'record the source debit');

        $this->recordThat(new TransferSourceDebited($this->id));
    }

    public function reject(string $reason): void
    {
        $this->assertStatus(TransferStatus::Initiated, 'be rejected');

        $this->recordThat(new TransferRejected($this->id, $this->terms, $reason));
    }

    public function complete(): void
    {
        $this->assertStatus(TransferStatus::SourceDebited, 'complete');

        $this->recordThat(new TransferCompleted($this->id, $this->terms));
    }

    public function markCreditFailed(string $reason): void
    {
        $this->assertStatus(TransferStatus::SourceDebited, 'record a failed credit');

        $this->recordThat(new TransferCreditFailed($this->id, $reason));
    }

    public function markCompensated(): void
    {
        $this->assertStatus(TransferStatus::CreditFailed, 'be compensated');

        $this->recordThat(new TransferCompensated($this->id, $this->terms, $this->failureReason));
    }

    public function aggregateId(): string
    {
        return $this->id->value;
    }

    public function id(): TransferId
    {
        return $this->id;
    }

    public function terms(): TransferTerms
    {
        return $this->terms;
    }

    public function status(): TransferStatus
    {
        return $this->status;
    }

    public function failureReason(): ?string
    {
        return $this->failureReason;
    }

    private function assertStatus(TransferStatus $required, string $action): void
    {
        if ($this->status !== $required) {
            throw IllegalTransferTransition::from($this->id, $this->status, $action);
        }
    }

    protected function apply(DomainEvent $event): void
    {
        match (true) {
            $event instanceof TransferInitiated => $this->applyTransferInitiated($event),
            $event instanceof TransferSourceDebited => $this->status = TransferStatus::SourceDebited,
            $event instanceof TransferCompleted => $this->status = TransferStatus::Completed,
            $event instanceof TransferRejected => $this->fail(TransferStatus::Rejected, $event->reason),
            $event instanceof TransferCreditFailed => $this->fail(TransferStatus::CreditFailed, $event->reason),
            $event instanceof TransferCompensated => $this->status = TransferStatus::Compensated,
            default => throw new LogicException(sprintf('Transfer cannot apply %s.', $event::class)),
        };
    }

    private function applyTransferInitiated(TransferInitiated $event): void
    {
        $this->id = $event->transferId;
        $this->terms = $event->terms;
        $this->status = TransferStatus::Initiated;
    }

    private function fail(TransferStatus $status, ?string $reason): void
    {
        $this->status = $status;
        $this->failureReason = $reason;
    }
}
