<?php

declare(strict_types=1);

namespace Ledger\Infrastructure\EventStore;

use Illuminate\Database\ConnectionInterface;
use Ledger\EventSourcing\Clock\Clock;
use Ledger\EventSourcing\Snapshot\Snapshot;
use Ledger\EventSourcing\Snapshot\SnapshotStore;
use Ledger\Infrastructure\Database\Row;
use Ledger\Infrastructure\Database\Timestamp;

/**
 * Keeps one row per stream; a snapshot only ever replaces an older one,
 * so two concurrent writers cannot move the cached state backwards.
 */
final readonly class DatabaseSnapshotStore implements SnapshotStore
{
    private const TABLE = 'snapshots';

    public function __construct(
        private ConnectionInterface $db,
        private Clock $clock,
    ) {}

    public function latest(string $streamId): ?Snapshot
    {
        $row = $this->db->table(self::TABLE)->where('stream_id', $streamId)->first();

        if (! is_object($row)) {
            return null;
        }

        return new Snapshot(
            Row::string($row, 'stream_id'),
            Row::int($row, 'version'),
            Row::int($row, 'schema_version'),
            Row::json($row, 'state'),
        );
    }

    public function save(Snapshot $snapshot): void
    {
        $values = [
            'version' => $snapshot->version,
            'schema_version' => $snapshot->schemaVersion,
            'state' => json_encode($snapshot->state, JSON_THROW_ON_ERROR),
            'created_at' => Timestamp::toColumn($this->clock->now()),
        ];

        $replaced = $this->db->table(self::TABLE)
            ->where('stream_id', $snapshot->streamId)
            ->where('version', '<', $snapshot->version)
            ->update($values);

        if ($replaced === 0) {
            $this->db->table(self::TABLE)->insertOrIgnore(['stream_id' => $snapshot->streamId] + $values);
        }
    }
}
