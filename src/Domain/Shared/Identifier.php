<?php

declare(strict_types=1);

namespace Ledger\Domain\Shared;

use Ledger\EventSourcing\Uuid;
use Stringable;

/**
 * Base for UUID-backed identifiers. Each subclass is its own type, so an
 * AccountId can never be passed where a TransferId is expected.
 */
abstract readonly class Identifier implements Stringable
{
    final protected function __construct(public string $value) {}

    public static function fromString(string $value): static
    {
        $normalized = strtolower($value);

        if (! Uuid::isValid($normalized)) {
            throw InvalidIdentifier::for(static::class, $value);
        }

        return new static($normalized);
    }

    public static function generate(): static
    {
        return new static(Uuid::v4());
    }

    public function equals(self $other): bool
    {
        return $other instanceof static && $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
