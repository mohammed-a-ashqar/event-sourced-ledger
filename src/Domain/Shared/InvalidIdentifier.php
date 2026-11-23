<?php

declare(strict_types=1);

namespace Ledger\Domain\Shared;

final class InvalidIdentifier extends BusinessRuleViolation
{
    /**
     * @param  class-string  $type
     */
    public static function for(string $type, string $value): self
    {
        $shortName = substr($type, (int) strrpos($type, '\\') + 1);

        return new self(sprintf('"%s" is not a valid %s (UUID expected).', $value, $shortName));
    }

    public function errorCode(): string
    {
        return 'invalid_identifier';
    }
}
