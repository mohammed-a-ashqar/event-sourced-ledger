<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Testing\PendingCommand;
use Ledger\Application\Bus\CommandBus;
use Ledger\Application\Messaging\MessagePublisher;
use Ledger\EventSourcing\Clock\Clock;
use Ledger\EventSourcing\Clock\FrozenClock;
use Ledger\Infrastructure\Messaging\InMemoryMessagePublisher;

/**
 * Base for tests that boot Laravel and talk to a real database
 * (SQLite in memory by default; MySQL or PostgreSQL in CI).
 */
abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected FrozenClock $clock;

    protected InMemoryMessagePublisher $publisher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clock = new FrozenClock('2026-03-01 09:00:00');
        $this->publisher = new InMemoryMessagePublisher;

        $this->app->instance(Clock::class, $this->clock);
        $this->app->instance(MessagePublisher::class, $this->publisher);
    }

    protected function bus(): CommandBus
    {
        return $this->app->make(CommandBus::class);
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    protected function command(string $command, array $parameters = []): PendingCommand
    {
        $pending = $this->artisan($command, $parameters);
        self::assertInstanceOf(PendingCommand::class, $pending);

        return $pending;
    }

    /**
     * Drivers return numbers as int or numeric string; tests compare ints.
     */
    protected static function int(mixed $value): int
    {
        self::assertIsNumeric($value);

        return (int) $value;
    }

    protected static function str(mixed $value): string
    {
        self::assertIsString($value);

        return $value;
    }
}
