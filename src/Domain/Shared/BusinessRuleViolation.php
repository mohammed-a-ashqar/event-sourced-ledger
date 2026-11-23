<?php

declare(strict_types=1);

namespace Ledger\Domain\Shared;

use DomainException;

/**
 * Base class for every rule the domain refuses to break.
 *
 * The error code is a stable, machine-readable identifier that adapters
 * (e.g. the HTTP API) can expose without parsing messages.
 */
abstract class BusinessRuleViolation extends DomainException
{
    abstract public function errorCode(): string;
}
