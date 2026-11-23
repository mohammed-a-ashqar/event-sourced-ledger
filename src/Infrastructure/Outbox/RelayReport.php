<?php

declare(strict_types=1);

namespace Ledger\Infrastructure\Outbox;

final readonly class RelayReport
{
    public function __construct(
        public int $published,
        public int $failed,
    ) {}
}
