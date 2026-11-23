<?php

declare(strict_types=1);

namespace Tests\Unit\Application;

use ArrayObject;
use DomainException;
use Ledger\Application\Bus\ConcurrencyRetry;
use Ledger\Application\Bus\CorrelateCommand;
use Ledger\Application\Bus\Middleware;
use Ledger\Application\Bus\NoHandlerForCommand;
use Ledger\Application\Bus\RetryOnConcurrencyConflict;
use Ledger\Application\Bus\SimpleCommandBus;
use Ledger\EventSourcing\ConcurrencyException;
use Ledger\EventSourcing\CorrelationContext;
use Ledger\EventSourcing\Uuid;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

final class CommandBusTest extends TestCase
{
    #[Test]
    public function middleware_wrap_the_handler_in_the_given_order(): void
    {
        /** @var ArrayObject<int, string> $trace */
        $trace = new ArrayObject;
        $tracer = fn (string $name) => new class($name, $trace) implements Middleware
        {
            /** @param ArrayObject<int, string> $trace */
            public function __construct(private string $name, private ArrayObject $trace) {}

            public function handle(object $command, callable $next): void
            {
                $this->trace[] = $this->name.':before';
                $next($command);
                $this->trace[] = $this->name.':after';
            }
        };

        $bus = new SimpleCommandBus(
            [stdClass::class => function () use ($trace): void {
                $trace[] = 'handler';
            }],
            [$tracer('outer'), $tracer('inner')],
        );

        $bus->dispatch(new stdClass);

        self::assertSame(['outer:before', 'inner:before', 'handler', 'inner:after', 'outer:after'], $trace->getArrayCopy());
    }

    #[Test]
    public function a_command_without_handler_is_a_programming_error(): void
    {
        $this->expectException(NoHandlerForCommand::class);

        (new SimpleCommandBus([]))->dispatch(new stdClass);
    }

    #[Test]
    public function the_retry_middleware_reruns_the_handler_after_a_conflict(): void
    {
        $attempts = 0;
        $bus = new SimpleCommandBus(
            [stdClass::class => function () use (&$attempts): void {
                if (++$attempts < 3) {
                    throw ConcurrencyException::versionConflict('s', 1);
                }
            }],
            [new RetryOnConcurrencyConflict(new ConcurrencyRetry(3))],
        );

        $bus->dispatch(new stdClass);

        self::assertSame(3, $attempts);
    }

    #[Test]
    public function the_retry_is_bounded(): void
    {
        $attempts = 0;
        $bus = new SimpleCommandBus(
            [stdClass::class => function () use (&$attempts): void {
                $attempts++;

                throw ConcurrencyException::versionConflict('s', 1);
            }],
            [new RetryOnConcurrencyConflict(new ConcurrencyRetry(3))],
        );

        try {
            $bus->dispatch(new stdClass);
            self::fail('The conflict should surface once attempts are exhausted.');
        } catch (ConcurrencyException) {
            self::assertSame(3, $attempts);
        }
    }

    #[Test]
    public function other_failures_are_not_retried(): void
    {
        $attempts = 0;
        $retry = new ConcurrencyRetry(5);

        try {
            $retry->run(function () use (&$attempts): void {
                $attempts++;

                throw new DomainException('business rule');
            });
        } catch (DomainException) {
            self::assertSame(1, $attempts);
        }
    }

    #[Test]
    public function each_command_gets_its_own_causation_id_within_the_request_correlation(): void
    {
        $context = new CorrelationContext;
        $context->useCorrelationId('request-42');
        $seen = [];

        $bus = new SimpleCommandBus(
            [stdClass::class => function () use ($context, &$seen): void {
                $seen[] = [$context->correlationId(), $context->causationId()];
            }],
            [new CorrelateCommand($context)],
        );

        $bus->dispatch(new stdClass);
        $bus->dispatch(new stdClass);

        self::assertSame('request-42', $seen[0][0]);
        self::assertSame('request-42', $seen[1][0]);
        self::assertTrue(Uuid::isValid((string) $seen[0][1]));
        self::assertNotSame($seen[0][1], $seen[1][1]);
        self::assertNull($context->causationId());
    }
}
