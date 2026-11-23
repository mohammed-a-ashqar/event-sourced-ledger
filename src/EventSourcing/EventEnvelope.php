<?php

declare(strict_types=1);

namespace Ledger\EventSourcing;

/**
 * A domain event together with where and when it was stored.
 *
 * The position is the global, monotonically increasing sequence number
 * assigned by the event store; it is null until the event has been appended.
 */
final readonly class EventEnvelope
{
    public function __construct(
        public string $streamId,
        public int $version,
        public DomainEvent $event,
        public Metadata $metadata,
        public ?int $position = null,
    ) {}

    public function withPosition(int $position): self
    {
        return new self($this->streamId, $this->version, $this->event, $this->metadata, $position);
    }

    public function eventId(): string
    {
        return $this->metadata->eventId;
    }
}
