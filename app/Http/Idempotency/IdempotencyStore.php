<?php

declare(strict_types=1);

namespace App\Http\Idempotency;

use Illuminate\Database\ConnectionInterface;
use Ledger\EventSourcing\Clock\Clock;
use Ledger\Infrastructure\Database\Row;
use Ledger\Infrastructure\Database\Timestamp;

/**
 * Remembers which Idempotency-Key produced which response.
 *
 * A key is first reserved (insert-or-ignore, so exactly one of two racing
 * requests wins), then completed with the response once it is known.
 */
final readonly class IdempotencyStore
{
    private const TABLE = 'idempotency_keys';

    public function __construct(
        private ConnectionInterface $db,
        private Clock $clock,
    ) {}

    public function find(string $key): ?IdempotencyRecord
    {
        $row = $this->db->table(self::TABLE)->where('idempotency_key', $key)->first();

        if (! is_object($row)) {
            return null;
        }

        $status = Row::nullableString($row, 'completed_at') === null ? null : Row::int($row, 'status_code');

        return new IdempotencyRecord(
            Row::string($row, 'fingerprint'),
            Timestamp::fromColumn(Row::string($row, 'created_at')),
            $status === null ? null : new StoredResponse(
                $status,
                Row::string($row, 'response_body'),
                self::headers(Row::string($row, 'response_headers')),
            ),
        );
    }

    /**
     * @return bool false when another request holds the key already
     */
    public function reserve(string $key, string $fingerprint): bool
    {
        return $this->db->table(self::TABLE)->insertOrIgnore([
            'idempotency_key' => $key,
            'fingerprint' => $fingerprint,
            'created_at' => Timestamp::toColumn($this->clock->now()),
        ]) === 1;
    }

    public function complete(string $key, StoredResponse $response): void
    {
        $this->db->table(self::TABLE)->where('idempotency_key', $key)->update([
            'status_code' => $response->status,
            'response_body' => $response->body,
            'response_headers' => json_encode($response->headers, JSON_THROW_ON_ERROR),
            'completed_at' => Timestamp::toColumn($this->clock->now()),
        ]);
    }

    public function release(string $key): void
    {
        $this->db->table(self::TABLE)->where('idempotency_key', $key)->delete();
    }

    /**
     * @return array<string, string>
     */
    private static function headers(string $json): array
    {
        $decoded = json_decode($json, true);
        $headers = [];

        foreach (is_array($decoded) ? $decoded : [] as $name => $value) {
            if (is_string($name) && is_string($value)) {
                $headers[$name] = $value;
            }
        }

        return $headers;
    }
}
