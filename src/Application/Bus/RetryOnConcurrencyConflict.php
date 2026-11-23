<?php

declare(strict_types=1);

namespace Ledger\Application\Bus;

/**
 * Retries the whole command handler (reload, decide, append) on a version conflict.
 */
final readonly class RetryOnConcurrencyConflict implements Middleware
{
    public function __construct(private ConcurrencyRetry $retry) {}

    public function handle(object $command, callable $next): void
    {
        $this->retry->run(static fn () => $next($command));
    }
}
