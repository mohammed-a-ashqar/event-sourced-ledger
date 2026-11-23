<?php

declare(strict_types=1);

namespace Ledger\EventSourcing;

use DateTimeImmutable;
use Ledger\EventSourcing\Clock\Clock;
use Ledger\EventSourcing\Snapshot\Snapshot;
use Ledger\EventSourcing\Snapshot\Snapshotable;
use Ledger\EventSourcing\Snapshot\SnapshotPolicy;
use Ledger\EventSourcing\Snapshot\SnapshotStore;

/**
 * Loads and saves one aggregate type from/to its event streams.
 *
 * Loading prefers "latest snapshot + tail of events" and falls back to a
 * full replay. Saving appends the pending events with optimistic concurrency
 * (expected version = version the aggregate was loaded at) and takes a new
 * snapshot when the policy says so.
 *
 * @template T of AggregateRoot
 */
final readonly class AggregateRepository
{
    /**
     * @param  class-string<T>  $aggregateClass
     */
    public function __construct(
        private EventStore $eventStore,
        private string $aggregateClass,
        private string $streamPrefix,
        private Clock $clock,
        private CorrelationContext $correlation,
        private ?SnapshotStore $snapshots = null,
        private ?SnapshotPolicy $snapshotPolicy = null,
    ) {}

    public function streamId(string $aggregateId): string
    {
        return $this->streamPrefix.'-'.$aggregateId;
    }

    /**
     * @return T|null
     */
    public function load(string $aggregateId): ?AggregateRoot
    {
        $streamId = $this->streamId($aggregateId);
        $aggregate = $this->restoreFromSnapshot($streamId);

        if ($aggregate !== null) {
            $aggregate->replay(self::events($this->eventStore->load($streamId, $aggregate->version())));

            return $aggregate;
        }

        $history = $this->eventStore->load($streamId);

        return $history === [] ? null : $this->aggregateClass::reconstituteFromHistory(self::events($history));
    }

    /**
     * Rebuilds the aggregate as it was at a point in time (temporal query).
     *
     * @return T|null
     */
    public function loadAsOf(string $aggregateId, DateTimeImmutable $pointInTime): ?AggregateRoot
    {
        $history = $this->eventStore->loadUntil($this->streamId($aggregateId), $pointInTime);

        return $history === [] ? null : $this->aggregateClass::reconstituteFromHistory(self::events($history));
    }

    /**
     * @param  T  $aggregate
     * @return list<EventEnvelope>
     */
    public function save(AggregateRoot $aggregate): array
    {
        $pending = $aggregate->pendingEvents();

        if ($pending === []) {
            return [];
        }

        $streamId = $this->streamId($aggregate->aggregateId());
        $expectedVersion = $aggregate->persistedVersion();

        $stored = $this->eventStore->append($streamId, $expectedVersion, $this->envelopes($streamId, $expectedVersion, $pending));

        $aggregate->markPendingEventsAsPersisted();
        $this->snapshotIfDue($aggregate, $streamId, $expectedVersion);

        $lastWritten = end($stored);
        if ($lastWritten !== false) {
            $this->correlation->causedBy($lastWritten->eventId());
        }

        return $stored;
    }

    /**
     * @param  non-empty-list<DomainEvent>  $events
     * @return list<EventEnvelope>
     */
    private function envelopes(string $streamId, int $expectedVersion, array $events): array
    {
        $now = $this->clock->now();
        $envelopes = [];

        foreach ($events as $offset => $event) {
            $envelopes[] = new EventEnvelope(
                $streamId,
                $expectedVersion + $offset + 1,
                $event,
                new Metadata(Uuid::v4(), $this->correlation->correlationId(), $this->correlation->causationId(), $now),
            );
        }

        return $envelopes;
    }

    /**
     * @return T|null
     */
    private function restoreFromSnapshot(string $streamId): ?AggregateRoot
    {
        $class = $this->aggregateClass;

        if ($this->snapshots === null || ! is_subclass_of($class, Snapshotable::class)) {
            return null;
        }

        $snapshot = $this->snapshots->latest($streamId);

        if ($snapshot === null || $snapshot->schemaVersion !== $class::snapshotSchemaVersion()) {
            return null;
        }

        /** @var T $aggregate */
        $aggregate = $class::restoreFromSnapshot($snapshot->state, $snapshot->version);

        return $aggregate;
    }

    private function snapshotIfDue(AggregateRoot $aggregate, string $streamId, int $versionBefore): void
    {
        if (
            $this->snapshots === null
            || $this->snapshotPolicy === null
            || ! $aggregate instanceof Snapshotable
            || ! $this->snapshotPolicy->shouldSnapshot($versionBefore, $aggregate->version())
        ) {
            return;
        }

        $this->snapshots->save(new Snapshot(
            $streamId,
            $aggregate->version(),
            $aggregate::snapshotSchemaVersion(),
            $aggregate->snapshotState(),
        ));
    }

    /**
     * @param  list<EventEnvelope>  $envelopes
     * @return list<DomainEvent>
     */
    private static function events(array $envelopes): array
    {
        return array_map(static fn (EventEnvelope $envelope): DomainEvent => $envelope->event, $envelopes);
    }
}
