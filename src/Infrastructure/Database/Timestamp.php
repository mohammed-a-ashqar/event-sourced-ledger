<?php

declare(strict_types=1);

namespace Ledger\Infrastructure\Database;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * One timestamp format for every driver: UTC with microseconds.
 *
 * It sorts lexicographically, so range filters behave the same on SQLite
 * (which stores text) as on MySQL and PostgreSQL.
 */
final class Timestamp
{
    private const FORMAT = 'Y-m-d H:i:s.u';

    public static function toColumn(DateTimeInterface $moment): string
    {
        return DateTimeImmutable::createFromInterface($moment)->setTimezone(new DateTimeZone('UTC'))->format(self::FORMAT);
    }

    public static function toIso8601(string $column): string
    {
        return self::fromColumn($column)->format('Y-m-d\TH:i:s.up');
    }

    public static function fromColumn(string $column): DateTimeImmutable
    {
        return new DateTimeImmutable($column, new DateTimeZone('UTC'));
    }
}
