<?php

declare(strict_types=1);

namespace Ledger\EventSourcing\Upcasting;

use RuntimeException;

final class MissingUpcaster extends RuntimeException
{
    public static function for(string $eventType, int $fromVersion): self
    {
        return new self(sprintf('No upcaster turns "%s" v%d into v%d.', $eventType, $fromVersion, $fromVersion + 1));
    }
}
