<?php

declare(strict_types=1);

namespace Tests\Unit\EventSourcing;

use Ledger\EventSourcing\EventStore;
use Ledger\EventSourcing\InMemoryEventStore;
use PHPUnit\Framework\TestCase;
use Tests\Support\EventStoreContract;

final class InMemoryEventStoreTest extends TestCase
{
    use EventStoreContract;

    protected function createEventStore(array $listeners = []): EventStore
    {
        return new InMemoryEventStore($listeners);
    }
}
