<?php

declare(strict_types=1);

namespace Ledger\Application\Bus;

/**
 * Routes each command to exactly one handler through a middleware pipeline.
 *
 * Middleware run in the order given: the first one wraps all the others.
 */
final readonly class SimpleCommandBus implements CommandBus
{
    /**
     * @param  array<class-string, callable>  $handlers  command class => handler receiving an instance of it
     * @param  list<Middleware>  $middleware
     */
    public function __construct(
        private array $handlers,
        private array $middleware = [],
    ) {}

    public function dispatch(object $command): void
    {
        $handler = $this->handlers[$command::class] ?? throw NoHandlerForCommand::for($command);

        $pipeline = array_reduce(
            array_reverse($this->middleware),
            static fn (callable $next, Middleware $middleware): callable => static fn (object $c) => $middleware->handle($c, $next),
            $handler,
        );

        $pipeline($command);
    }
}
