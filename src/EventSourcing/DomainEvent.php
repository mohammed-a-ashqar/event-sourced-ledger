<?php

declare(strict_types=1);

namespace Ledger\EventSourcing;

/**
 * A fact that happened inside an aggregate.
 *
 * Events describe themselves with a stable type name and a schema version so
 * the event store never depends on PHP class names, and old payloads can be
 * upcast when the schema evolves.
 */
interface DomainEvent
{
    public static function eventType(): string;

    public static function schemaVersion(): int;

    /**
     * @return array<string, scalar|null>
     */
    public function toPayload(): array;

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromPayload(array $payload): self;
}
