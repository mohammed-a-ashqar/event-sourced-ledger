<?php

declare(strict_types=1);

namespace Ledger\EventSourcing\Serialization;

use RuntimeException;

final class UnknownEventType extends RuntimeException
{
    public static function named(string $type): self
    {
        return new self(sprintf('No event class is registered for type "%s".', $type));
    }
}
