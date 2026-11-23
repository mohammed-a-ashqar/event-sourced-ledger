<?php

declare(strict_types=1);

namespace App\Providers;

use App\Http\Middleware\EnforceIdempotency;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\ServiceProvider;
use Ledger\Application\Account\DepositMoney;
use Ledger\Application\Account\DepositMoneyHandler;
use Ledger\Application\Account\FreezeAccount;
use Ledger\Application\Account\FreezeAccountHandler;
use Ledger\Application\Account\OpenAccount;
use Ledger\Application\Account\OpenAccountHandler;
use Ledger\Application\Account\UnfreezeAccount;
use Ledger\Application\Account\UnfreezeAccountHandler;
use Ledger\Application\Account\WithdrawMoney;
use Ledger\Application\Account\WithdrawMoneyHandler;
use Ledger\Application\Bus\CommandBus;
use Ledger\Application\Bus\ConcurrencyRetry;
use Ledger\Application\Bus\CorrelateCommand;
use Ledger\Application\Bus\RetryOnConcurrencyConflict;
use Ledger\Application\Bus\SimpleCommandBus;
use Ledger\Application\Messaging\MessagePublisher;
use Ledger\Application\Transfer\TransferMoney;
use Ledger\Application\Transfer\TransferMoneyHandler;
use Ledger\Domain\Account\Account;
use Ledger\Domain\Account\AccountRepository;
use Ledger\Domain\Transfer\Transfer;
use Ledger\Domain\Transfer\TransferRepository;
use Ledger\EventSourcing\AggregateRepository;
use Ledger\EventSourcing\Clock\Clock;
use Ledger\EventSourcing\Clock\SystemClock;
use Ledger\EventSourcing\CorrelationContext;
use Ledger\EventSourcing\EventStore;
use Ledger\EventSourcing\Serialization\EventSerializer;
use Ledger\EventSourcing\Snapshot\EveryNEvents;
use Ledger\EventSourcing\Snapshot\SnapshotStore;
use Ledger\Infrastructure\EventStore\DatabaseEventStore;
use Ledger\Infrastructure\EventStore\DatabaseSnapshotStore;
use Ledger\Infrastructure\Messaging\InMemoryMessagePublisher;
use Ledger\Infrastructure\Messaging\LogMessagePublisher;
use Ledger\Infrastructure\Outbox\IntegrationEventMapper;
use Ledger\Infrastructure\Outbox\OutboxRelay;
use Ledger\Infrastructure\Outbox\OutboxWriter;
use Ledger\Infrastructure\Persistence\EventSourcedAccountRepository;
use Ledger\Infrastructure\Persistence\EventSourcedTransferRepository;
use Ledger\Infrastructure\Projection\AccountBalancesProjector;
use Ledger\Infrastructure\Projection\AccountStatementProjector;
use Ledger\Infrastructure\Projection\JournalProjector;
use Ledger\Infrastructure\Projection\ProjectAfterCommand;
use Ledger\Infrastructure\Projection\ProjectionRunner;
use Ledger\Infrastructure\Projection\TransfersProjector;
use Ledger\Infrastructure\Serialization\LedgerEventTypes;
use Psr\Log\LoggerInterface;
use UnexpectedValueException;

/**
 * Composition root: the only place where ports meet their adapters.
 */
final class LedgerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Clock::class, SystemClock::class);
        $this->app->scoped(CorrelationContext::class);
        $this->app->singleton(EventSerializer::class, static fn (): EventSerializer => LedgerEventTypes::serializer());
        $this->app->singleton(ConcurrencyRetry::class, fn (): ConcurrencyRetry => new ConcurrencyRetry($this->configInt('ledger.command_max_attempts')));

        $this->registerEventStore();
        $this->registerRepositories();
        $this->registerReadSide();
        $this->registerMessaging();
        $this->registerCommandBus();

        $this->app->when(EnforceIdempotency::class)
            ->needs('$ttlHours')
            ->give(fn (): int => $this->configInt('ledger.idempotency.ttl_hours'));
    }

    private function registerEventStore(): void
    {
        $this->app->singleton(EventStore::class, static fn (Application $app): EventStore => new DatabaseEventStore(
            $app->make(ConnectionInterface::class),
            $app->make(EventSerializer::class),
            [$app->make(OutboxWriter::class)],
        ));

        $this->app->singleton(SnapshotStore::class, DatabaseSnapshotStore::class);
        $this->app->singleton(OutboxWriter::class, static fn (Application $app): OutboxWriter => new OutboxWriter(
            $app->make(ConnectionInterface::class),
            new IntegrationEventMapper,
        ));
    }

    private function registerRepositories(): void
    {
        $this->app->scoped(AccountRepository::class, fn (Application $app): AccountRepository => new EventSourcedAccountRepository(
            new AggregateRepository(
                $app->make(EventStore::class),
                Account::class,
                EventSourcedAccountRepository::STREAM_PREFIX,
                $app->make(Clock::class),
                $app->make(CorrelationContext::class),
                $app->make(SnapshotStore::class),
                new EveryNEvents($this->configInt('ledger.snapshot_every')),
            ),
        ));

        $this->app->scoped(TransferRepository::class, static fn (Application $app): TransferRepository => new EventSourcedTransferRepository(
            new AggregateRepository(
                $app->make(EventStore::class),
                Transfer::class,
                EventSourcedTransferRepository::STREAM_PREFIX,
                $app->make(Clock::class),
                $app->make(CorrelationContext::class),
            ),
        ));
    }

    private function registerReadSide(): void
    {
        $this->app->singleton(ProjectionRunner::class, fn (Application $app): ProjectionRunner => new ProjectionRunner(
            $app->make(ConnectionInterface::class),
            $app->make(EventStore::class),
            $app->make(Clock::class),
            [
                $app->make(AccountBalancesProjector::class),
                $app->make(AccountStatementProjector::class),
                $app->make(JournalProjector::class),
                $app->make(TransfersProjector::class),
            ],
            $this->configInt('ledger.projections.batch_size'),
        ));
    }

    private function registerMessaging(): void
    {
        $this->app->singleton(MessagePublisher::class, static fn (Application $app): MessagePublisher => match (config('ledger.outbox.publisher')) {
            'memory' => new InMemoryMessagePublisher,
            default => new LogMessagePublisher($app->make(LoggerInterface::class)),
        });

        $this->app->singleton(OutboxRelay::class, fn (Application $app): OutboxRelay => new OutboxRelay(
            $app->make(ConnectionInterface::class),
            $app->make(MessagePublisher::class),
            $app->make(Clock::class),
            $this->configInt('ledger.outbox.max_attempts'),
        ));
    }

    private function registerCommandBus(): void
    {
        $this->app->scoped(CommandBus::class, static function (Application $app): CommandBus {
            // Handlers are resolved lazily, so dispatching one command builds only what it needs.
            $handlers = [
                OpenAccount::class => static fn (OpenAccount $c) => $app->make(OpenAccountHandler::class)($c),
                DepositMoney::class => static fn (DepositMoney $c) => $app->make(DepositMoneyHandler::class)($c),
                WithdrawMoney::class => static fn (WithdrawMoney $c) => $app->make(WithdrawMoneyHandler::class)($c),
                FreezeAccount::class => static fn (FreezeAccount $c) => $app->make(FreezeAccountHandler::class)($c),
                UnfreezeAccount::class => static fn (UnfreezeAccount $c) => $app->make(UnfreezeAccountHandler::class)($c),
                TransferMoney::class => static fn (TransferMoney $c) => $app->make(TransferMoneyHandler::class)($c),
            ];

            $middleware = [$app->make(CorrelateCommand::class)];

            if (config('ledger.projections.sync') === true) {
                $middleware[] = $app->make(ProjectAfterCommand::class);
            }

            $middleware[] = $app->make(RetryOnConcurrencyConflict::class);

            return new SimpleCommandBus($handlers, $middleware);
        });
    }

    private function configInt(string $key): int
    {
        $value = config($key);

        return is_numeric($value) ? (int) $value : throw new UnexpectedValueException(sprintf('Config "%s" must be numeric.', $key));
    }
}
