<?php

declare(strict_types=1);

namespace Ledger\EventSourcing;

use RuntimeException;
use Throwable;

/**
 * Raised when a stream changed between loading an aggregate and saving it.
 */
final class ConcurrencyException extends RuntimeException
{
    public static function versionConflict(string $streamId, int $expectedVersion, ?Throwable $previous = null): self
    {
        return new self(
            sprintf('Stream "%s" is no longer at version %d; another writer appended first.', $streamId, $expectedVersion),
            0,
            $previous,
        );
    }

    public static function streamBehind(string $streamId, int $expectedVersion): self
    {
        return new self(sprintf('Stream "%s" has not reached the expected version %d.', $streamId, $expectedVersion));
    }
}
