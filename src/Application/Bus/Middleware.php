<?php

declare(strict_types=1);

namespace Ledger\Application\Bus;

interface Middleware
{
    /**
     * @param  callable(object): void  $next
     */
    public function handle(object $command, callable $next): void;
}
