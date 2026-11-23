<?php

declare(strict_types=1);

namespace Ledger\EventSourcing;

/**
 * Typed accessors for decoded event payloads.
 *
 * Payloads come from JSON, so every read is checked instead of trusted.
 */
final class Payload
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public static function string(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;

        if (! is_string($value)) {
            throw MalformedPayload::expected($key, 'string', $value);
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function nullableString(array $payload, string $key): ?string
    {
        $value = $payload[$key] ?? null;

        if ($value !== null && ! is_string($value)) {
            throw MalformedPayload::expected($key, 'string or null', $value);
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function int(array $payload, string $key): int
    {
        $value = $payload[$key] ?? null;

        if (! is_int($value)) {
            throw MalformedPayload::expected($key, 'integer', $value);
        }

        return $value;
    }
}
