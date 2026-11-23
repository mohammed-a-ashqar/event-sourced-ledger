<?php

declare(strict_types=1);

namespace Ledger\Application\Messaging;

use DateTimeImmutable;

/**
 * A message for other systems.
 *
 * Unlike domain events (internal, free to change), integration messages are
 * a published contract: they are versioned in their type name
 * ("ledger.transfer.completed.v1") and only expose what consumers need.
 */
final readonly class IntegrationMessage
{
    /**
     * @param  array<string, scalar|null>  $payload
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public string $messageId,
        public string $type,
        public array $payload,
        public array $headers,
        public DateTimeImmutable $occurredAt,
    ) {}
}
