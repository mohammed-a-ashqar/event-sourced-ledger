<?php

declare(strict_types=1);

namespace Ledger\Infrastructure\Messaging;

use Ledger\Application\Messaging\IntegrationMessage;
use Ledger\Application\Messaging\MessagePublisher;
use Ledger\EventSourcing\Metadata;
use Psr\Log\LoggerInterface;

/**
 * Development adapter: "publishes" by writing a structured log line.
 *
 * A broker adapter (RabbitMQ, Kafka, SQS...) implements the same port; see
 * the README section on the outbox for an example.
 */
final readonly class LogMessagePublisher implements MessagePublisher
{
    public function __construct(private LoggerInterface $logger) {}

    public function publish(IntegrationMessage $message): void
    {
        $this->logger->info('Integration message published', [
            'message_id' => $message->messageId,
            'type' => $message->type,
            'payload' => $message->payload,
            'headers' => $message->headers,
            'occurred_at' => $message->occurredAt->format(Metadata::DATE_FORMAT),
        ]);
    }
}
