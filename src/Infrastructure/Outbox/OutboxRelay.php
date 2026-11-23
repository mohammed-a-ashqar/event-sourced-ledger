<?php

declare(strict_types=1);

namespace Ledger\Infrastructure\Outbox;

use Illuminate\Database\ConnectionInterface;
use Ledger\Application\Messaging\IntegrationMessage;
use Ledger\Application\Messaging\MessagePublisher;
use Ledger\EventSourcing\Clock\Clock;
use Ledger\Infrastructure\Database\Row;
use Ledger\Infrastructure\Database\Timestamp;
use Throwable;

/**
 * The "read" half of the transactional outbox: hands pending messages to
 * the broker in insertion order and marks them as published.
 *
 * A crash between publish() and the update means the message is sent again
 * on the next run (at-least-once). A message that keeps failing is retried
 * up to max attempts and then left aside for inspection, so one poison
 * message cannot block the rest.
 */
final readonly class OutboxRelay
{
    private const TABLE = 'outbox_messages';

    public function __construct(
        private ConnectionInterface $db,
        private MessagePublisher $publisher,
        private Clock $clock,
        private int $maxAttempts,
    ) {}

    public function relay(int $limit): RelayReport
    {
        $published = 0;
        $failed = 0;

        $pending = $this->db->table(self::TABLE)
            ->whereNull('published_at')
            ->where('attempts', '<', $this->maxAttempts)
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($pending as $row) {
            $id = Row::int($row, 'id');

            try {
                $this->publisher->publish(self::message($row));
            } catch (Throwable $failure) {
                $this->recordFailure($id, Row::int($row, 'attempts') + 1, $failure);
                $failed++;

                continue;
            }

            $this->db->table(self::TABLE)->where('id', $id)->update([
                'published_at' => Timestamp::toColumn($this->clock->now()),
                'attempts' => Row::int($row, 'attempts') + 1,
                'last_error' => null,
            ]);
            $published++;
        }

        return new RelayReport($published, $failed);
    }

    private function recordFailure(int $id, int $attempts, Throwable $failure): void
    {
        $this->db->table(self::TABLE)->where('id', $id)->update([
            'attempts' => $attempts,
            'last_error' => mb_substr($failure::class.': '.$failure->getMessage(), 0, 1000),
        ]);
    }

    private static function message(object $row): IntegrationMessage
    {
        /** @var array<string, scalar|null> $payload */
        $payload = Row::json($row, 'payload');
        /** @var array<string, string> $headers */
        $headers = Row::json($row, 'headers');

        return new IntegrationMessage(
            Row::string($row, 'message_id'),
            Row::string($row, 'message_type'),
            $payload,
            $headers,
            Timestamp::fromColumn(Row::string($row, 'occurred_at')),
        );
    }
}
