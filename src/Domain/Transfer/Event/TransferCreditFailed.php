<?php

declare(strict_types=1);

namespace Ledger\Domain\Transfer\Event;

use Ledger\Domain\Transfer\TransferId;
use Ledger\EventSourcing\DomainEvent;
use Ledger\EventSourcing\Payload;

final readonly class TransferCreditFailed implements DomainEvent
{
    public function __construct(
        public TransferId $transferId,
        public string $reason,
    ) {}

    public static function eventType(): string
    {
        return 'transfer.credit_failed';
    }

    public static function schemaVersion(): int
    {
        return 1;
    }

    public function toPayload(): array
    {
        return ['transfer_id' => $this->transferId->value, 'reason' => $this->reason];
    }

    public static function fromPayload(array $payload): self
    {
        return new self(
            TransferId::fromString(Payload::string($payload, 'transfer_id')),
            Payload::string($payload, 'reason'),
        );
    }
}
