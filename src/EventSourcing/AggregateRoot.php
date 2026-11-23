<?php

declare(strict_types=1);

namespace Ledger\EventSourcing;

/**
 * Base class for event-sourced aggregates.
 *
 * State is never set directly: behaviour methods call recordThat(), which
 * applies the event to the in-memory state and queues it for persistence.
 * Reconstitution replays the same apply() method, so there is exactly one
 * code path that mutates state.
 */
abstract class AggregateRoot
{
    private int $version = 0;

    /** @var list<DomainEvent> */
    private array $pendingEvents = [];

    final protected function __construct() {}

    abstract public function aggregateId(): string;

    abstract protected function apply(DomainEvent $event): void;

    /**
     * @param  iterable<DomainEvent>  $history
     */
    public static function reconstituteFromHistory(iterable $history): static
    {
        $aggregate = new static;
        $aggregate->replay($history);

        return $aggregate;
    }

    /**
     * Applies already-persisted events, e.g. the tail after a snapshot.
     *
     * @param  iterable<DomainEvent>  $events
     */
    public function replay(iterable $events): void
    {
        foreach ($events as $event) {
            $this->apply($event);
            $this->version++;
        }
    }

    /**
     * Version including events that are not persisted yet.
     */
    public function version(): int
    {
        return $this->version;
    }

    /**
     * Version of the stream as it was when the aggregate was loaded.
     */
    public function persistedVersion(): int
    {
        return $this->version - count($this->pendingEvents);
    }

    /**
     * @return list<DomainEvent>
     */
    public function pendingEvents(): array
    {
        return $this->pendingEvents;
    }

    public function markPendingEventsAsPersisted(): void
    {
        $this->pendingEvents = [];
    }

    protected function recordThat(DomainEvent $event): void
    {
        $this->apply($event);
        $this->version++;
        $this->pendingEvents[] = $event;
    }

    /**
     * Creates an empty instance positioned at a snapshot's version.
     */
    final protected static function blankAtVersion(int $version): static
    {
        $aggregate = new static;
        $aggregate->version = $version;

        return $aggregate;
    }
}
