<?php

declare(strict_types=1);

namespace Ledger\Domain\Transfer\Event;

use Ledger\Domain\Transfer\TransferId;
use Ledger\EventSourcing\DomainEvent;
use Ledger\EventSourcing\Payload;

final readonly class TransferSourceDebited implements DomainEvent
{
    public function __construct(public TransferId $transferId) {}

    public static function eventType(): string
    {
        return 'transfer.source_debited';
    }

    public static function schemaVersion(): int
    {
        return 1;
    }

    public function toPayload(): array
    {
        return ['transfer_id' => $this->transferId->value];
    }

    public static function fromPayload(array $payload): self
    {
        return new self(TransferId::fromString(Payload::string($payload, 'transfer_id')));
    }
}
