<?php

declare(strict_types=1);

namespace Ledger\EventSourcing;

use DateTimeImmutable;

/**
 * Append-only storage for event streams (port).
 */
interface EventStore
{
    /**
     * Appends events to a stream, provided the stream is still at the expected version.
     *
     * The envelopes must carry consecutive versions starting at $expectedVersion + 1.
     *
     * @param  list<EventEnvelope>  $events
     * @return list<EventEnvelope> the same events, with their global positions
     *
     * @throws ConcurrencyException when another writer appended to the stream first
     */
    public function append(string $streamId, int $expectedVersion, array $events): array;

    /**
     * @return list<EventEnvelope> events of the stream with a version greater than $afterVersion
     */
    public function load(string $streamId, int $afterVersion = 0): array;

    /**
     * @return list<EventEnvelope> events of the stream that occurred at or before $pointInTime
     */
    public function loadUntil(string $streamId, DateTimeImmutable $pointInTime): array;

    /**
     * Reads the global log (all streams) in position order.
     *
     * @return list<EventEnvelope>
     */
    public function loadFromPosition(int $afterPosition, int $limit): array;
}
