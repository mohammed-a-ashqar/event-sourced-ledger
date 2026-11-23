<?php

declare(strict_types=1);

namespace Tests\Support;

use DateTimeImmutable;
use Ledger\EventSourcing\EventStore;

/**
 * Lets a test slip a competing write in between "load" and "append",
 * reproducing a real race deterministically.
 */
final class InterleavingEventStore implements EventStore
{
    /** @var list<callable(): void> */
    private array $competingWrites = [];

    public function __construct(private readonly EventStore $inner) {}

    /**
     * @param  callable(): void  $write  runs right before the next append, as another writer would
     */
    public function interleaveBeforeNextAppend(callable $write): void
    {
        $this->competingWrites[] = $write;
    }

    public function append(string $streamId, int $expectedVersion, array $events): array
    {
        $competingWrite = array_shift($this->competingWrites);

        if ($competingWrite !== null) {
            $competingWrite();
        }

        return $this->inner->append($streamId, $expectedVersion, $events);
    }

    public function load(string $streamId, int $afterVersion = 0): array
    {
        return $this->inner->load($streamId, $afterVersion);
    }

    public function loadUntil(string $streamId, DateTimeImmutable $pointInTime): array
    {
        return $this->inner->loadUntil($streamId, $pointInTime);
    }

    public function loadFromPosition(int $afterPosition, int $limit): array
    {
        return $this->inner->loadFromPosition($afterPosition, $limit);
    }
}
