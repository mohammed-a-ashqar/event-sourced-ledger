<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use InvalidArgumentException;
use Ledger\Infrastructure\Projection\ProjectionRunner;

final class RebuildProjections extends Command
{
    protected $signature = 'ledger:projections:rebuild
                            {projection?* : Projections to rebuild (default: all)}';

    protected $description = 'Empty read models and replay the whole event store into them';

    public function handle(ProjectionRunner $runner): int
    {
        /** @var list<string> $requested */
        $requested = (array) $this->argument('projection');
        $projections = $requested === [] ? $runner->names() : $requested;

        foreach ($projections as $projection) {
            $started = microtime(true);

            try {
                $replayed = $runner->rebuild($projection);
            } catch (InvalidArgumentException $unknown) {
                $this->error($unknown->getMessage());

                return self::FAILURE;
            }

            $this->info(sprintf(
                '%-20s rebuilt from %d event(s) in %.1f ms',
                $projection,
                $replayed,
                (microtime(true) - $started) * 1000,
            ));
        }

        return self::SUCCESS;
    }
}
