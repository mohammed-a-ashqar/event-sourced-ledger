<?php

declare(strict_types=1);

namespace Ledger\EventSourcing\Serialization;

final readonly class SerializedEvent
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $eventType,
        public int $schemaVersion,
        public array $payload,
    ) {}
}
