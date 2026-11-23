<?php

declare(strict_types=1);

namespace Ledger\EventSourcing\Clock;

use DateTimeImmutable;
use DateTimeZone;

/**
 * A clock that only moves when told to; used for deterministic temporal tests.
 */
final class FrozenClock implements Clock
{
    private DateTimeImmutable $now;

    public function __construct(string $now = '2026-01-01 00:00:00')
    {
        $this->now = new DateTimeImmutable($now, new DateTimeZone('UTC'));
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function setTo(string $now): void
    {
        $this->now = new DateTimeImmutable($now, new DateTimeZone('UTC'));
    }

    public function advance(string $interval): void
    {
        $this->now = $this->now->modify($interval);
    }
}
