<?php

declare(strict_types=1);

namespace Ledger\Infrastructure\EventStore;

use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Ledger\EventSourcing\AppendListener;
use Ledger\EventSourcing\ConcurrencyException;
use Ledger\EventSourcing\EventEnvelope;
use Ledger\EventSourcing\EventStore;
use Ledger\EventSourcing\Metadata;
use Ledger\EventSourcing\Serialization\EventSerializer;
use Ledger\EventSourcing\Serialization\SerializedEvent;
use Ledger\EventSourcing\StreamVersions;
use Ledger\Infrastructure\Database\Row;
use Ledger\Infrastructure\Database\Timestamp;

/**
 * Event store on a single relational table, portable across SQLite, MySQL and PostgreSQL.
 *
 * Concurrency control is delegated to the database: the unique index on
 * (stream_id, version) guarantees that of two writers that loaded the same
 * version, exactly one append succeeds. No locks are held while deciding.
 */
final readonly class DatabaseEventStore implements EventStore
{
    private const TABLE = 'stored_events';

    /**
     * @param  list<AppendListener>  $listeners  run inside the append transaction (e.g. the outbox)
     */
    public function __construct(
        private ConnectionInterface $db,
        private EventSerializer $serializer,
        private array $listeners = [],
    ) {}

    public function append(string $streamId, int $expectedVersion, array $events): array
    {
        StreamVersions::assertConsecutive($streamId, $expectedVersion, $events);

        try {
            return $this->db->transaction(function () use ($streamId, $expectedVersion, $events): array {
                $this->assertStreamReached($streamId, $expectedVersion);

                $stored = array_map(
                    fn (EventEnvelope $event): EventEnvelope => $event->withPosition($this->insert($event)),
                    $events,
                );

                foreach ($this->listeners as $listener) {
                    $listener->onAppend($stored);
                }

                return $stored;
            });
        } catch (UniqueConstraintViolationException $conflict) {
            throw ConcurrencyException::versionConflict($streamId, $expectedVersion, $conflict);
        }
    }

    public function load(string $streamId, int $afterVersion = 0): array
    {
        return $this->hydrate(
            $this->db->table(self::TABLE)
                ->where('stream_id', $streamId)
                ->where('version', '>', $afterVersion)
                ->orderBy('version')
                ->get(),
        );
    }

    public function loadUntil(string $streamId, DateTimeImmutable $pointInTime): array
    {
        return $this->hydrate(
            $this->db->table(self::TABLE)
                ->where('stream_id', $streamId)
                ->where('occurred_at', '<=', Timestamp::toColumn($pointInTime))
                ->orderBy('version')
                ->get(),
        );
    }

    public function loadFromPosition(int $afterPosition, int $limit): array
    {
        return $this->hydrate(
            $this->db->table(self::TABLE)
                ->where('position', '>', $afterPosition)
                ->orderBy('position')
                ->limit($limit)
                ->get(),
        );
    }

    /**
     * A stream can only be behind the expected version through a caller bug
     * (events are never deleted), but appending would then leave a gap.
     */
    private function assertStreamReached(string $streamId, int $expectedVersion): void
    {
        if ($expectedVersion === 0) {
            return;
        }

        $reached = $this->db->table(self::TABLE)
            ->where('stream_id', $streamId)
            ->where('version', $expectedVersion)
            ->exists();

        if (! $reached) {
            throw ConcurrencyException::streamBehind($streamId, $expectedVersion);
        }
    }

    private function insert(EventEnvelope $event): int
    {
        $serialized = $this->serializer->serialize($event->event);

        $position = $this->db->table(self::TABLE)->insertGetId([
            'stream_id' => $event->streamId,
            'version' => $event->version,
            'event_type' => $serialized->eventType,
            'schema_version' => $serialized->schemaVersion,
            'payload' => json_encode($serialized->payload, JSON_THROW_ON_ERROR),
            'metadata' => json_encode($event->metadata->toArray(), JSON_THROW_ON_ERROR),
            'occurred_at' => Timestamp::toColumn($event->metadata->occurredAt),
        ], 'position');

        return (int) $position;
    }

    /**
     * @param  iterable<object>  $rows
     * @return list<EventEnvelope>
     */
    private function hydrate(iterable $rows): array
    {
        $envelopes = [];

        foreach ($rows as $row) {
            $envelopes[] = new EventEnvelope(
                Row::string($row, 'stream_id'),
                Row::int($row, 'version'),
                $this->serializer->deserialize(new SerializedEvent(
                    Row::string($row, 'event_type'),
                    Row::int($row, 'schema_version'),
                    Row::json($row, 'payload'),
                )),
                Metadata::fromArray(Row::json($row, 'metadata')),
                Row::int($row, 'position'),
            );
        }

        return $envelopes;
    }
}
