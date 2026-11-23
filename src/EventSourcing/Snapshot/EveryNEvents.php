<?php

declare(strict_types=1);

namespace Ledger\EventSourcing\Snapshot;

use InvalidArgumentException;

/**
 * Takes a snapshot whenever a save crosses a multiple of N.
 *
 * Checking for a crossed boundary (not "version % N === 0") matters because
 * one save can append several events and jump over the exact multiple.
 */
final readonly class EveryNEvents implements SnapshotPolicy
{
    public function __construct(private int $interval)
    {
        if ($interval < 1) {
            throw new InvalidArgumentException('Snapshot interval must be at least 1.');
        }
    }

    public function shouldSnapshot(int $versionBefore, int $versionAfter): bool
    {
        return intdiv($versionAfter, $this->interval) > intdiv($versionBefore, $this->interval);
    }
}
