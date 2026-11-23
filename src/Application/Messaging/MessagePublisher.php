<?php

declare(strict_types=1);

namespace Ledger\Application\Messaging;

/**
 * Outbound port to a message broker.
 *
 * Implementations must throw when a message could not be handed over, so
 * the outbox relay keeps it and tries again. Delivery is at-least-once:
 * consumers deduplicate on the message id.
 */
interface MessagePublisher
{
    public function publish(IntegrationMessage $message): void;
}
