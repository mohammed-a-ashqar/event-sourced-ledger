<?php

declare(strict_types=1);

namespace Ledger\Domain\Transfer\Event;

use Ledger\Domain\Transfer\TransferId;
use Ledger\Domain\Transfer\TransferTerms;
use Ledger\EventSourcing\DomainEvent;
use Ledger\EventSourcing\Payload;

/**
 * A final transfer event. It repeats the terms so consumers (projections,
 * integration events) do not have to look up the start of the stream.
 */
abstract readonly class TransferOutcome implements DomainEvent
{
    final public function __construct(
        public TransferId $transferId,
        public TransferTerms $terms,
        public ?string $reason = null,
    ) {}

    public static function schemaVersion(): int
    {
        return 1;
    }

    public function toPayload(): array
    {
        return ['transfer_id' => $this->transferId->value] + $this->terms->toPayload() + ['reason' => $this->reason];
    }

    public static function fromPayload(array $payload): static
    {
        return new static(
            TransferId::fromString(Payload::string($payload, 'transfer_id')),
            TransferTerms::fromPayload($payload),
            Payload::nullableString($payload, 'reason'),
        );
    }
}
