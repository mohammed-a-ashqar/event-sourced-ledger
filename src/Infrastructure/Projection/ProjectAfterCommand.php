<?php

declare(strict_types=1);

namespace Ledger\Infrastructure\Projection;

use Ledger\Application\Bus\Middleware;
use Throwable;

/**
 * Synchronous projection mode: catch the read models up right after a
 * command, so an API client can read its own writes.
 *
 * Projections also run when the command failed half-way (a saga may have
 * written some steps before failing); the command's own error wins.
 */
final readonly class ProjectAfterCommand implements Middleware
{
    public function __construct(private ProjectionRunner $runner) {}

    public function handle(object $command, callable $next): void
    {
        try {
            $next($command);
        } catch (Throwable $failure) {
            $this->runner->runAll();

            throw $failure;
        }

        $this->runner->runAll();
    }
}
