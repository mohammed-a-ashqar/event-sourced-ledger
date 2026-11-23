<?php

declare(strict_types=1);

namespace Ledger\EventSourcing\Snapshot;

/**
 * An aggregate whose state can be captured and restored without replaying its whole stream.
 */
interface Snapshotable
{
    /**
     * Bumped whenever the shape of snapshotState() changes; older snapshots are then ignored.
     */
    public static function snapshotSchemaVersion(): int;

    /**
     * @return array<string, mixed>
     */
    public function snapshotState(): array;

    /**
     * @param  array<string, mixed>  $state
     */
    public static function restoreFromSnapshot(array $state, int $version): static;
}
