<?php

declare(strict_types=1);

namespace Tests\Support;

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
use Ledger\Application\Bus\ConcurrencyRetry;
use Ledger\Application\Bus\CorrelateCommand;
use Ledger\Application\Bus\RetryOnConcurrencyConflict;
use Ledger\Application\Bus\SimpleCommandBus;
use Ledger\Application\Transfer\TransferMoney;
use Ledger\Application\Transfer\TransferMoneyHandler;
use Ledger\Application\Transfer\TransferProcessManager;
use Ledger\Domain\Account\Account;
use Ledger\Domain\Account\AccountId;
use Ledger\Domain\Shared\TransactionId;
use Ledger\Domain\Transfer\Transfer;
use Ledger\EventSourcing\AggregateRepository;
use Ledger\EventSourcing\Clock\FrozenClock;
use Ledger\EventSourcing\CorrelationContext;
use Ledger\EventSourcing\EventStore;
use Ledger\EventSourcing\InMemoryEventStore;
use Ledger\EventSourcing\Snapshot\EveryNEvents;
use Ledger\EventSourcing\Snapshot\InMemorySnapshotStore;
use Ledger\Infrastructure\Persistence\EventSourcedAccountRepository;
use Ledger\Infrastructure\Persistence\EventSourcedTransferRepository;

/**
 * The whole write side wired with in-memory adapters, for fast application tests.
 */
final class InMemoryLedger
{
    public readonly InMemoryEventStore $events;

    public readonly InterleavingEventStore $store;

    public readonly InMemorySnapshotStore $snapshots;

    public readonly FrozenClock $clock;

    public readonly CorrelationContext $correlation;

    public readonly EventSourcedAccountRepository $accounts;

    public readonly EventSourcedTransferRepository $transfers;

    public readonly TransferProcessManager $processManager;

    public readonly SimpleCommandBus $bus;

    public function __construct(int $snapshotEvery = 3, int $maxAttempts = 3)
    {
        $this->events = new InMemoryEventStore;
        $this->store = new InterleavingEventStore($this->events);
        $this->snapshots = new InMemorySnapshotStore;
        $this->clock = new FrozenClock('2026-03-01 09:00:00');
        $this->correlation = new CorrelationContext;

        $this->accounts = new EventSourcedAccountRepository($this->accountRepository($this->store, $snapshotEvery));
        $this->transfers = new EventSourcedTransferRepository(new AggregateRepository(
            $this->store,
            Transfer::class,
            EventSourcedTransferRepository::STREAM_PREFIX,
            $this->clock,
            $this->correlation,
        ));

        $retry = new ConcurrencyRetry($maxAttempts);
        $this->processManager = new TransferProcessManager($this->transfers, $this->accounts, $retry);

        $this->bus = new SimpleCommandBus(
            [
                OpenAccount::class => fn (OpenAccount $c) => (new OpenAccountHandler($this->accounts))($c),
                DepositMoney::class => fn (DepositMoney $c) => (new DepositMoneyHandler($this->accounts))($c),
                WithdrawMoney::class => fn (WithdrawMoney $c) => (new WithdrawMoneyHandler($this->accounts))($c),
                FreezeAccount::class => fn (FreezeAccount $c) => (new FreezeAccountHandler($this->accounts))($c),
                UnfreezeAccount::class => fn (UnfreezeAccount $c) => (new UnfreezeAccountHandler($this->accounts))($c),
                TransferMoney::class => fn (TransferMoney $c) => (new TransferMoneyHandler($this->accounts, $this->processManager))($c),
            ],
            [new CorrelateCommand($this->correlation), new RetryOnConcurrencyConflict($retry)],
        );
    }

    /**
     * A second, independent writer on the same underlying store (no interleaving).
     */
    public function otherWriter(): EventSourcedAccountRepository
    {
        return new EventSourcedAccountRepository($this->accountRepository($this->events, 0));
    }

    public function openAccount(AccountId $id, int $deposit = 0, int $overdraft = 0, string $currency = 'EUR'): void
    {
        $this->bus->dispatch(new OpenAccount($id, 'Owner '.substr($id->value, 0, 4), $currency, $overdraft));

        if ($deposit > 0) {
            $this->bus->dispatch(new DepositMoney($id, TransactionId::generate(), $deposit, $currency));
        }
    }

    public function balanceOf(AccountId $id): int
    {
        return $this->accounts->get($id)->balance()->amount;
    }

    /**
     * @return AggregateRepository<Account>
     */
    private function accountRepository(EventStore $store, int $snapshotEvery): AggregateRepository
    {
        return new AggregateRepository(
            $store,
            Account::class,
            EventSourcedAccountRepository::STREAM_PREFIX,
            $this->clock,
            $this->correlation,
            $snapshotEvery > 0 ? $this->snapshots : null,
            $snapshotEvery > 0 ? new EveryNEvents($snapshotEvery) : null,
        );
    }
}
