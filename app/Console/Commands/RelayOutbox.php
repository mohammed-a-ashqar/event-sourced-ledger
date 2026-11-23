<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Ledger\Infrastructure\Outbox\OutboxRelay;

final class RelayOutbox extends Command
{
    protected $signature = 'ledger:outbox:relay
                            {--limit=100 : Maximum number of messages to publish in this run}';

    protected $description = 'Publish pending integration messages from the transactional outbox';

    public function handle(OutboxRelay $relay): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($limit === false) {
            $this->error('--limit must be a positive integer.');

            return self::INVALID;
        }

        $report = $relay->relay($limit);

        $this->info(sprintf('Published %d message(s), %d failed.', $report->published, $report->failed));

        return $report->failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
