<?php

declare(strict_types=1);

namespace Ledger\EventSourcing\Clock;

use DateTimeImmutable;

interface Clock
{
    public function now(): DateTimeImmutable;
}
