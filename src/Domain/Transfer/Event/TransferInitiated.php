<?php

declare(strict_types=1);

namespace Ledger\Domain\Transfer\Event;

use Ledger\Domain\Transfer\TransferId;
use Ledger\Domain\Transfer\TransferTerms;
use Ledger\EventSourcing\DomainEvent;
use Ledger\EventSourcing\Payload;

final readonly class TransferInitiated implements DomainEvent
{
    public function __construct(
        public TransferId $transferId,
        public TransferTerms $terms,
    ) {}

    public static function eventType(): string
    {
        return 'transfer.initiated';
    }

    public static function schemaVersion(): int
    {
        return 1;
    }

    public function toPayload(): array
    {
        return ['transfer_id' => $this->transferId->value] + $this->terms->toPayload();
    }

    public static function fromPayload(array $payload): self
    {
        return new self(TransferId::fromString(Payload::string($payload, 'transfer_id')), TransferTerms::fromPayload($payload));
    }
}
