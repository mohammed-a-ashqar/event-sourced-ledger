<?php

declare(strict_types=1);

namespace Ledger\Application\Bus;

use Ledger\EventSourcing\CorrelationContext;
use Ledger\EventSourcing\Uuid;

/**
 * Gives every dispatched command an id that becomes the causation id of
 * the first events it writes. The correlation id of the surrounding
 * request (if any) is kept.
 */
final readonly class CorrelateCommand implements Middleware
{
    public function __construct(private CorrelationContext $context) {}

    public function handle(object $command, callable $next): void
    {
        $this->context->within(null, Uuid::v4(), static fn () => $next($command));
    }
}
