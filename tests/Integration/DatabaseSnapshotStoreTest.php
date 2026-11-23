<?php

declare(strict_types=1);

namespace Tests\Integration;

use Ledger\Application\Account\DepositMoney;
use Ledger\Application\Account\OpenAccount;
use Ledger\Domain\Account\AccountRepository;
use Ledger\Domain\Shared\TransactionId;
use Ledger\EventSourcing\Snapshot\Snapshot;
use Ledger\EventSourcing\Snapshot\SnapshotStore;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Fixtures as F;
use Tests\TestCase;

final class DatabaseSnapshotStoreTest extends TestCase
{
    private function snapshots(): SnapshotStore
    {
        return $this->app->make(SnapshotStore::class);
    }

    #[Test]
    public function it_stores_and_returns_the_latest_snapshot(): void
    {
        $this->snapshots()->save(new Snapshot('account-x', 50, 1, ['balance' => 100, 'legs' => ['a' => 1]]));

        $snapshot = $this->snapshots()->latest('account-x');

        self::assertNotNull($snapshot);
        self::assertSame(50, $snapshot->version);
        self::assertSame(1, $snapshot->schemaVersion);
        self::assertEquals(['balance' => 100, 'legs' => ['a' => 1]], $snapshot->state);
        self::assertNull($this->snapshots()->latest('account-y'));
    }

    #[Test]
    public function a_newer_snapshot_replaces_an_older_one_but_never_the_reverse(): void
    {
        $this->snapshots()->save(new Snapshot('account-x', 50, 1, ['v' => 50]));
        $this->snapshots()->save(new Snapshot('account-x', 100, 1, ['v' => 100]));
        $this->snapshots()->save(new Snapshot('account-x', 75, 1, ['v' => 75]));

        self::assertSame(['v' => 100], $this->snapshots()->latest('account-x')?->state);
    }

    #[Test]
    public function the_repository_snapshots_accounts_on_the_configured_interval(): void
    {
        config(['ledger.snapshot_every' => 5]);
        $this->app->forgetScopedInstances();

        $this->bus()->dispatch(new OpenAccount(F::alice(), 'Alice', 'EUR', 0));
        for ($i = 0; $i < 6; $i++) {
            $this->bus()->dispatch(new DepositMoney(F::alice(), TransactionId::generate(), 100, 'EUR'));
        }

        $snapshot = $this->snapshots()->latest('account-'.F::ALICE);
        self::assertNotNull($snapshot);
        self::assertSame(5, $snapshot->version);
        self::assertSame(400, $snapshot->state['balance']);

        $account = $this->app->make(AccountRepository::class)->get(F::alice());
        self::assertSame(600, $account->balance()->amount);
        self::assertSame(7, $account->version());
    }
}
