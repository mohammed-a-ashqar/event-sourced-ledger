<?php

declare(strict_types=1);

namespace Ledger\EventSourcing;

use RuntimeException;

final class MalformedPayload extends RuntimeException
{
    public static function expected(string $key, string $type, mixed $actual): self
    {
        return new self(sprintf('Payload key "%s" must be %s, got %s.', $key, $type, get_debug_type($actual)));
    }
}
