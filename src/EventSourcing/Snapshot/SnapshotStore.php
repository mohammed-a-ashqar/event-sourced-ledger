<?php

declare(strict_types=1);

namespace Ledger\EventSourcing\Snapshot;

/**
 * Keeps the latest snapshot per stream (port).
 *
 * Snapshots are a cache: losing them only costs replay time, never data.
 */
interface SnapshotStore
{
    public function latest(string $streamId): ?Snapshot;

    public function save(Snapshot $snapshot): void;
}
