<?php

declare(strict_types=1);

namespace Ledger\EventSourcing\Snapshot;

final class InMemorySnapshotStore implements SnapshotStore
{
    /** @var array<string, Snapshot> */
    private array $snapshots = [];

    public function latest(string $streamId): ?Snapshot
    {
        return $this->snapshots[$streamId] ?? null;
    }

    public function save(Snapshot $snapshot): void
    {
        $this->snapshots[$snapshot->streamId] = $snapshot;
    }
}
