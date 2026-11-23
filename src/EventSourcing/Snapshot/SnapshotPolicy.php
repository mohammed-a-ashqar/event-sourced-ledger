<?php

declare(strict_types=1);

namespace Ledger\EventSourcing\Snapshot;

interface SnapshotPolicy
{
    public function shouldSnapshot(int $versionBefore, int $versionAfter): bool;
}
