<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Ledger\Infrastructure\Projection\ProjectionRunner;

final class RunProjections extends Command
{
    protected $signature = 'ledger:projections:run';

    protected $description = 'Catch every read model up with the event store (asynchronous projection mode)';

    public function handle(ProjectionRunner $runner): int
    {
        foreach ($runner->runAll() as $projection => $applied) {
            $this->line(sprintf('%-20s %d new event(s) applied', $projection, $applied));
        }

        return self::SUCCESS;
    }
}
