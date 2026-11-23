<?php

declare(strict_types=1);

namespace Ledger\EventSourcing;

use InvalidArgumentException;

/**
 * Guards shared by every event store implementation.
 */
final class StreamVersions
{
    /**
     * @param  list<EventEnvelope>  $events
     */
    public static function assertConsecutive(string $streamId, int $expectedVersion, array $events): void
    {
        if ($expectedVersion < 0) {
            throw new InvalidArgumentException('Expected version cannot be negative.');
        }

        if ($events === []) {
            throw new InvalidArgumentException('Nothing to append.');
        }

        foreach ($events as $offset => $event) {
            if ($event->streamId !== $streamId || $event->version !== $expectedVersion + $offset + 1) {
                throw new InvalidArgumentException(sprintf(
                    'Event #%d must belong to stream "%s" with version %d.',
                    $offset,
                    $streamId,
                    $expectedVersion + $offset + 1,
                ));
            }
        }
    }
}
