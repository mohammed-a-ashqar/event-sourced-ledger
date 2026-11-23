<?php

declare(strict_types=1);

namespace Ledger\EventSourcing\Snapshot;

final readonly class Snapshot
{
    /**
     * @param  array<string, mixed>  $state
     */
    public function __construct(
        public string $streamId,
        public int $version,
        public int $schemaVersion,
        public array $state,
    ) {}
}
