<?php

declare(strict_types=1);

namespace Ledger\EventSourcing;

use DateTimeImmutable;

/**
 * Reference implementation of the event store contract, used by fast unit tests.
 *
 * It follows exactly the same rules as the database store (same contract
 * test suite), so tests written against it stay honest.
 */
final class InMemoryEventStore implements EventStore
{
    /** @var list<EventEnvelope> */
    private array $log = [];

    /** @var array<string, int> */
    private array $streamVersions = [];

    /**
     * @param  list<AppendListener>  $listeners
     */
    public function __construct(private readonly array $listeners = []) {}

    public function append(string $streamId, int $expectedVersion, array $events): array
    {
        StreamVersions::assertConsecutive($streamId, $expectedVersion, $events);

        $currentVersion = $this->streamVersions[$streamId] ?? 0;

        if ($currentVersion > $expectedVersion) {
            throw ConcurrencyException::versionConflict($streamId, $expectedVersion);
        }

        if ($currentVersion < $expectedVersion) {
            throw ConcurrencyException::streamBehind($streamId, $expectedVersion);
        }

        $stored = [];

        foreach ($events as $event) {
            $stored[] = $event->withPosition(count($this->log) + count($stored) + 1);
        }

        foreach ($this->listeners as $listener) {
            $listener->onAppend($stored);
        }

        array_push($this->log, ...$stored);
        $this->streamVersions[$streamId] = $expectedVersion + count($stored);

        return $stored;
    }

    public function load(string $streamId, int $afterVersion = 0): array
    {
        return array_values(array_filter(
            $this->log,
            static fn (EventEnvelope $e): bool => $e->streamId === $streamId && $e->version > $afterVersion,
        ));
    }

    public function loadUntil(string $streamId, DateTimeImmutable $pointInTime): array
    {
        return array_values(array_filter(
            $this->log,
            static fn (EventEnvelope $e): bool => $e->streamId === $streamId && $e->metadata->occurredAt <= $pointInTime,
        ));
    }

    public function loadFromPosition(int $afterPosition, int $limit): array
    {
        return array_slice($this->log, $afterPosition, $limit);
    }
}
