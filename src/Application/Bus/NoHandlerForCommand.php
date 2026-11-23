<?php

declare(strict_types=1);

namespace Ledger\Application\Bus;

use LogicException;

final class NoHandlerForCommand extends LogicException
{
    public static function for(object $command): self
    {
        return new self(sprintf('No handler is registered for %s.', $command::class));
    }
}
