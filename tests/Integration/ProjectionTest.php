<?php

declare(strict_types=1);

namespace Tests\Integration;

use Illuminate\Support\Facades\DB;
use Ledger\Application\Account\DepositMoney;
use Ledger\Application\Account\FreezeAccount;
use Ledger\Application\Account\OpenAccount;
use Ledger\Application\Account\WithdrawMoney;
use Ledger\Application\Transfer\TransferMoney;
use Ledger\Domain\Account\AccountId;
use Ledger\Domain\Shared\TransactionId;
use Ledger\Domain\Transfer\TransferId;
use Ledger\EventSourcing\EventStore;
use Ledger\Infrastructure\Projection\AccountStatementProjector;
use Ledger\Infrastructure\Projection\ProjectionRunner;
use Ledger\Infrastructure\ReadModel\AccountReadModel;
use Ledger\Infrastructure\ReadModel\TransferReadModel;
use Ledger\Infrastructure\ReadModel\TrialBalance;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Fixtures as F;
use Tests\TestCase;

final class ProjectionTest extends TestCase
{
    private const READ_MODEL_TABLES = ['account_balances', 'account_statement_lines', 'journal_lines', 'transfers'];

    private function runner(): ProjectionRunner
    {
        return $this->app->make(ProjectionRunner::class);
    }

    /**
     * Alice: +1000, -150, transfer 300 to Bob (completed), transfer 100 to Carol (compensated, Carol frozen).
     */
    private function recordSomeHistory(): void
    {
        $carol = AccountId::fromString('55555555-5555-4555-8555-555555555555');

        $this->bus()->dispatch(new OpenAccount(F::alice(), 'Alice', 'EUR', 0));
        $this->bus()->dispatch(new OpenAccount(F::bob(), 'Bob', 'EUR', 0));
        $this->bus()->dispatch(new OpenAccount($carol, 'Carol', 'EUR', 0));
        $this->bus()->dispatch(new DepositMoney(F::alice(), TransactionId::generate(), 1_000, 'EUR', 'salary'));
        $this->bus()->dispatch(new WithdrawMoney(F::alice(), TransactionId::generate(), 150, 'EUR'));
        $this->bus()->dispatch(new TransferMoney(F::transferId(), F::alice(), F::bob(), 300, 'EUR'));
        $this->bus()->dispatch(new FreezeAccount($carol, 'compliance hold'));
        $this->bus()->dispatch(new TransferMoney(TransferId::generate(), F::alice(), $carol, 100, 'EUR'));
    }

    /**
     * @return array<string, array<int, array<array-key, mixed>>>
     */
    private function snapshotOfReadModels(): array
    {
        $tables = [];

        foreach (self::READ_MODEL_TABLES as $table) {
            $tables[$table] = DB::table($table)->get()
                ->map(fn (object $row) => array_diff_key((array) $row, ['id' => true]))
                ->sortBy(fn (array $row) => json_encode($row))
                ->values()
                ->all();
        }

        return $tables;
    }

    #[Test]
    public function the_account_summary_reflects_every_movement(): void
    {
        $this->recordSomeHistory();

        $alice = $this->app->make(AccountReadModel::class)->find(F::ALICE);
        $bob = $this->app->make(AccountReadModel::class)->find(F::BOB);

        self::assertNotNull($alice);
        self::assertNotNull($bob);
        self::assertSame(550, $alice['balance']['amount']);
        self::assertSame('5.50', $alice['balance']['formatted']);
        self::assertSame(300, $bob['balance']['amount']);
        self::assertSame('active', $alice['status']);
    }

    #[Test]
    public function the_statement_has_a_running_balance_and_reversal_lines(): void
    {
        $this->recordSomeHistory();

        $lines = $this->app->make(AccountReadModel::class)->statement(F::ALICE, 0, 50);

        self::assertSame(
            ['deposit', 'withdrawal', 'transfer_out', 'transfer_out', 'transfer_reversal'],
            array_column($lines, 'type'),
        );
        self::assertSame([1_000, 850, 550, 450, 550], array_map(fn (array $l) => $l['balance_after']['amount'], $lines));
        self::assertSame('salary', $lines[0]['description']);
    }

    #[Test]
    public function the_statement_is_paginated_by_position(): void
    {
        $this->recordSomeHistory();
        $model = $this->app->make(AccountReadModel::class);

        $firstPage = $model->statement(F::ALICE, 0, 2);
        $secondPage = $model->statement(F::ALICE, $firstPage[1]['position'], 2);

        self::assertSame(['deposit', 'withdrawal'], array_column($firstPage, 'type'));
        self::assertSame(['transfer_out', 'transfer_out'], array_column($secondPage, 'type'));
    }

    #[Test]
    public function transfers_are_projected_with_their_outcome(): void
    {
        $this->recordSomeHistory();

        $transfer = $this->app->make(TransferReadModel::class)->find(F::TRANSFER);

        self::assertNotNull($transfer);
        self::assertSame('completed', $transfer['status']);
        self::assertSame(300, $transfer['amount']['amount']);
        self::assertSame(1, DB::table('transfers')->where('status', 'compensated')->count());
    }

    #[Test]
    public function the_trial_balance_balances_and_the_clearing_account_is_empty(): void
    {
        $this->recordSomeHistory();

        $trialBalance = $this->app->make(TrialBalance::class)->compute();
        $byAccount = array_column($trialBalance['accounts'], 'balance', 'ledger_account');

        self::assertSame([['currency' => 'EUR', 'debits' => 1_950, 'credits' => 1_950, 'balanced' => true]], $trialBalance['currencies']);
        self::assertSame(0, $byAccount['system:transfer-clearing:EUR']);
        self::assertSame(-850, $byAccount['system:cash:EUR']);
        self::assertSame(550, $byAccount['customer:'.F::ALICE]);
        self::assertSame(300, $byAccount['customer:'.F::BOB]);
    }

    #[Test]
    public function journal_balances_agree_with_the_account_summary(): void
    {
        $this->recordSomeHistory();

        $journal = array_column($this->app->make(TrialBalance::class)->compute()['accounts'], 'balance', 'ledger_account');

        foreach (DB::table('account_balances')->get() as $account) {
            self::assertSame(self::int($account->balance), $journal['customer:'.self::str($account->account_id)] ?? 0);
        }
    }

    #[Test]
    public function running_projections_again_is_a_no_op(): void
    {
        $this->recordSomeHistory();
        $before = $this->snapshotOfReadModels();

        $applied = $this->runner()->runAll();

        self::assertSame(['account_balances' => 0, 'account_statement' => 0, 'journal' => 0, 'transfers' => 0], $applied);
        self::assertEquals($before, $this->snapshotOfReadModels());
    }

    #[Test]
    public function checkpoints_track_the_last_applied_position(): void
    {
        $this->recordSomeHistory();
        $lastPosition = self::int(DB::table('stored_events')->max('position'));

        foreach ($this->runner()->names() as $name) {
            self::assertSame($lastPosition, $this->runner()->checkpoint($name));
        }
    }

    #[Test]
    public function rebuilding_from_the_event_store_reproduces_identical_read_models(): void
    {
        $this->recordSomeHistory();
        $before = $this->snapshotOfReadModels();

        // Simulate a corrupted read model.
        DB::table('account_balances')->update(['balance' => 999_999]);
        DB::table('account_statement_lines')->delete();

        $replayed = [];
        foreach ($this->runner()->names() as $name) {
            $replayed[$name] = $this->runner()->rebuild($name);
        }

        self::assertEquals($before, $this->snapshotOfReadModels());
        self::assertSame(DB::table('stored_events')->count(), $replayed['account_balances']);
    }

    #[Test]
    public function the_rebuild_command_replays_selected_or_all_projections(): void
    {
        $this->recordSomeHistory();
        $before = $this->snapshotOfReadModels();
        DB::table('transfers')->delete();
        DB::table('journal_lines')->delete();

        $this->command('ledger:projections:rebuild', ['projection' => ['transfers']])
            ->expectsOutputToContain('transfers')
            ->assertSuccessful();

        self::assertSame(2, DB::table('transfers')->count());
        self::assertSame(0, DB::table('journal_lines')->count());

        $this->command('ledger:projections:rebuild')->assertSuccessful();

        self::assertEquals($before, $this->snapshotOfReadModels());
    }

    #[Test]
    public function the_rebuild_command_rejects_unknown_projections(): void
    {
        $this->command('ledger:projections:rebuild', ['projection' => ['nope']])
            ->expectsOutputToContain('Unknown projection "nope"')
            ->assertFailed();
    }

    #[Test]
    public function in_asynchronous_mode_the_run_command_catches_up(): void
    {
        config(['ledger.projections.sync' => false]);
        $this->app->forgetScopedInstances();

        $this->bus()->dispatch(new OpenAccount(F::alice(), 'Alice', 'EUR', 0));
        $this->bus()->dispatch(new DepositMoney(F::alice(), TransactionId::generate(), 500, 'EUR'));

        self::assertSame(0, DB::table('account_balances')->count(), 'Nothing is projected until a worker runs.');

        $this->command('ledger:projections:run')->assertSuccessful();

        self::assertSame(500, self::int(DB::table('account_balances')->value('balance')));
    }

    #[Test]
    public function catching_up_works_across_several_batches(): void
    {
        $this->bus()->dispatch(new OpenAccount(F::alice(), 'Alice', 'EUR', 0));
        for ($i = 0; $i < 7; $i++) {
            $this->bus()->dispatch(new DepositMoney(F::alice(), TransactionId::generate(), 10, 'EUR'));
        }

        $smallBatches = new ProjectionRunner(
            DB::connection(),
            $this->app->make(EventStore::class),
            $this->clock,
            [new AccountStatementProjector(DB::connection())],
            3,
        );

        self::assertSame(8, $smallBatches->rebuild('account_statement'));
        self::assertSame(self::int(DB::table('stored_events')->max('position')), $smallBatches->checkpoint('account_statement'));
        self::assertSame(70, self::int(DB::table('account_statement_lines')->orderByDesc('position')->value('balance_after')));
    }
}
