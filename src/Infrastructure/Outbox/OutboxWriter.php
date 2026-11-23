<?php

declare(strict_types=1);

namespace Ledger\Infrastructure\Outbox;

use Illuminate\Database\ConnectionInterface;
use Ledger\EventSourcing\AppendListener;
use Ledger\Infrastructure\Database\Timestamp;

/**
 * The "write" half of the transactional outbox.
 *
 * Registered as an append listener, it inserts integration messages in the
 * same transaction as the events: either both are committed or neither is,
 * so a message can never be lost or sent for an event that was rolled back.
 */
final readonly class OutboxWriter implements AppendListener
{
    public function __construct(
        private ConnectionInterface $db,
        private IntegrationEventMapper $mapper,
    ) {}

    public function onAppend(array $events): void
    {
        $rows = [];

        foreach ($events as $envelope) {
            $message = $this->mapper->map($envelope);

            if ($message === null) {
                continue;
            }

            $rows[] = [
                'message_id' => $message->messageId,
                'message_type' => $message->type,
                'payload' => json_encode($message->payload, JSON_THROW_ON_ERROR),
                'headers' => json_encode($message->headers, JSON_THROW_ON_ERROR),
                'occurred_at' => Timestamp::toColumn($message->occurredAt),
            ];
        }

        if ($rows !== []) {
            $this->db->table('outbox_messages')->insert($rows);
        }
    }
}
