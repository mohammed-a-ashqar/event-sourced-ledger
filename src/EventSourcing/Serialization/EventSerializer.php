<?php

declare(strict_types=1);

namespace Ledger\EventSourcing\Serialization;

use Ledger\EventSourcing\DomainEvent;
use Ledger\EventSourcing\Upcasting\UpcasterChain;

/**
 * Turns events into storable payloads and back.
 *
 * Reading always goes through the upcaster chain first, so the domain only
 * ever sees the current schema of an event.
 */
final readonly class EventSerializer
{
    public function __construct(
        private EventTypeRegistry $registry,
        private UpcasterChain $upcasters,
    ) {}

    public function serialize(DomainEvent $event): SerializedEvent
    {
        return new SerializedEvent($event::eventType(), $event::schemaVersion(), $event->toPayload());
    }

    public function deserialize(SerializedEvent $serialized): DomainEvent
    {
        $eventClass = $this->registry->classFor($serialized->eventType);

        $payload = $this->upcasters->upcast(
            $serialized->eventType,
            $serialized->schemaVersion,
            $eventClass::schemaVersion(),
            $serialized->payload,
        );

        return $eventClass::fromPayload($payload);
    }
}
