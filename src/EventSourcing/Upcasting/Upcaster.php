<?php

declare(strict_types=1);

namespace Ledger\EventSourcing\Upcasting;

/**
 * Migrates one event type's payload from one schema version to the next.
 *
 * Upcasters are pure functions over arrays: the stored history is never
 * rewritten, it is translated every time it is read.
 */
interface Upcaster
{
    public function eventType(): string;

    /**
     * The version this upcaster reads; it produces fromVersion() + 1.
     */
    public function fromVersion(): int;

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function upcast(array $payload): array;
}
