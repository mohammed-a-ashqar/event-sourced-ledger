<?php

declare(strict_types=1);

namespace Ledger\Infrastructure\Database;

use JsonException;
use UnexpectedValueException;

/**
 * Typed access to raw query-builder rows.
 *
 * Drivers disagree on types (MySQL may return numeric strings, PostgreSQL
 * returns JSON columns as text, ...), so every column is read through one
 * of these normalising accessors.
 */
final class Row
{
    public static function string(object $row, string $column): string
    {
        $value = self::value($row, $column);

        if (! is_string($value)) {
            throw self::unexpected($column, 'string', $value);
        }

        return $value;
    }

    public static function nullableString(object $row, string $column): ?string
    {
        return self::value($row, $column) === null ? null : self::string($row, $column);
    }

    public static function int(object $row, string $column): int
    {
        $value = self::value($row, $column);

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }

        throw self::unexpected($column, 'integer', $value);
    }

    /**
     * @return array<string, mixed>
     */
    public static function json(object $row, string $column): array
    {
        try {
            $decoded = json_decode(self::string($row, $column), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new UnexpectedValueException(sprintf('Column "%s" does not hold valid JSON.', $column), 0, $e);
        }

        if (! is_array($decoded)) {
            throw self::unexpected($column, 'a JSON object', $decoded);
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    private static function value(object $row, string $column): mixed
    {
        if (! property_exists($row, $column)) {
            throw new UnexpectedValueException(sprintf('Column "%s" is missing from the row.', $column));
        }

        return $row->{$column};
    }

    private static function unexpected(string $column, string $expected, mixed $actual): UnexpectedValueException
    {
        return new UnexpectedValueException(sprintf('Column "%s" should be %s, got %s.', $column, $expected, get_debug_type($actual)));
    }
}
