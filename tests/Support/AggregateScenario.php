<?php

declare(strict_types=1);

namespace Tests\Support;

use Ledger\EventSourcing\AggregateRoot;
use Ledger\EventSourcing\DomainEvent;
use PHPUnit\Framework\Assert;
use Throwable;

/**
 * Given/When/Then testing for event-sourced aggregates.
 *
 *   AggregateScenario::for(Account::class)
 *       ->given(new AccountOpened(...), new MoneyDeposited(...))
 *       ->when(fn (Account $account) => $account->withdraw(...))
 *       ->then(new MoneyWithdrawn(...));
 *
 * "Given" is history (already persisted), "then" is exactly the list of new
 * events the behaviour recorded, compared by value.
 *
 * @template T of AggregateRoot
 */
final class AggregateScenario
{
    /** @var T|null */
    private ?AggregateRoot $aggregate = null;

    private ?Throwable $failure = null;

    /**
     * @param  class-string<T>  $aggregateClass
     */
    private function __construct(private readonly string $aggregateClass) {}

    /**
     * @template A of AggregateRoot
     *
     * @param  class-string<A>  $aggregateClass
     * @return self<A>
     */
    public static function for(string $aggregateClass): self
    {
        return new self($aggregateClass);
    }

    /**
     * @return $this
     */
    public function given(DomainEvent ...$history): self
    {
        $this->aggregate = $this->aggregateClass::reconstituteFromHistory($history);

        return $this;
    }

    /**
     * @param  callable(T): mixed  $behaviour
     * @return $this
     */
    public function when(callable $behaviour): self
    {
        Assert::assertNotNull($this->aggregate, 'Call given() before when(), or use whenCreating().');

        try {
            $behaviour($this->aggregate);
        } catch (Throwable $failure) {
            $this->failure = $failure;
        }

        return $this;
    }

    /**
     * @param  callable(): T  $factory
     * @return $this
     */
    public function whenCreating(callable $factory): self
    {
        try {
            $this->aggregate = $factory();
        } catch (Throwable $failure) {
            $this->failure = $failure;
        }

        return $this;
    }

    /**
     * @return T
     */
    public function then(DomainEvent ...$expected): AggregateRoot
    {
        $aggregate = $this->succeeded();
        Assert::assertEquals($expected, $aggregate->pendingEvents(), 'Recorded events differ from the expected ones.');

        return $aggregate;
    }

    public function thenNothingHappened(): void
    {
        Assert::assertSame([], $this->succeeded()->pendingEvents(), 'Expected no new events.');
    }

    /**
     * @param  class-string<Throwable>  $exception
     */
    public function thenFails(string $exception, string $messageContains = ''): void
    {
        Assert::assertInstanceOf($exception, $this->failure, 'Expected the behaviour to fail.');
        Assert::assertStringContainsString($messageContains, $this->failure->getMessage());

        if ($this->aggregate !== null) {
            Assert::assertSame([], $this->aggregate->pendingEvents(), 'A failed behaviour must not record events.');
        }
    }

    /**
     * @return T
     */
    private function succeeded(): AggregateRoot
    {
        if ($this->failure !== null) {
            Assert::fail(sprintf('Unexpected %s: %s', $this->failure::class, $this->failure->getMessage()));
        }

        Assert::assertNotNull($this->aggregate);

        return $this->aggregate;
    }
}
