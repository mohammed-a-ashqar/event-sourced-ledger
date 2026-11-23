<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Ledger\Application\Transfer\TransferProcessManager;
use Ledger\Domain\Transfer\TransferId;
use Ledger\EventSourcing\Clock\Clock;
use Ledger\EventSourcing\CorrelationContext;
use Ledger\EventSourcing\Uuid;
use Ledger\Infrastructure\Projection\ProjectionRunner;
use Ledger\Infrastructure\ReadModel\TransferReadModel;
use Throwable;

/**
 * Recovery for sagas interrupted by a crash: picks up transfers that are
 * still in flight after a grace period and drives them to a final state.
 */
final class ResumeTransfers extends Command
{
    protected $signature = 'ledger:transfers:resume
                            {--older-than=60 : Only resume transfers idle for at least this many seconds}';

    protected $description = 'Drive stalled transfers to completion or compensation';

    public function handle(
        TransferReadModel $transfers,
        TransferProcessManager $processManager,
        ProjectionRunner $projections,
        CorrelationContext $correlation,
        Clock $clock,
    ): int {
        $seconds = filter_var($this->option('older-than'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);

        if ($seconds === false) {
            $this->error('--older-than must be a non-negative integer.');

            return self::INVALID;
        }

        $stalled = $transfers->stalledSince($clock->now()->modify(sprintf('-%d seconds', $seconds)));
        $failures = 0;

        foreach ($stalled as $transferId) {
            try {
                $transfer = $correlation->within(null, Uuid::v4(), fn () => $processManager->resume(TransferId::fromString($transferId)));
                $this->line(sprintf('%s -> %s', $transferId, $transfer->status()->value));
            } catch (Throwable $failure) {
                $failures++;
                $this->error(sprintf('%s could not be resumed: %s', $transferId, $failure->getMessage()));
            }
        }

        $projections->runAll();
        $this->info(sprintf('Resumed %d of %d stalled transfer(s).', count($stalled) - $failures, count($stalled)));

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
