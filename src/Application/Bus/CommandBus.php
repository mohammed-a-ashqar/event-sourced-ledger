<?php

declare(strict_types=1);

namespace Ledger\Application\Bus;

/**
 * Write side entry point. Commands change state and return nothing;
 * callers read the outcome from read models.
 */
interface CommandBus
{
    public function dispatch(object $command): void;
}
