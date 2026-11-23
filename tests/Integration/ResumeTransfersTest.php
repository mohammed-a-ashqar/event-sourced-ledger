<?php

declare(strict_types=1);

namespace Tests\Integration;

use Illuminate\Support\Facades\DB;
use Ledger\Application\Account\DepositMoney;
use Ledger\Application\Account\OpenAccount;
use Ledger\Domain\Account\AccountRepository;
use Ledger\Domain\Transfer\Transfer;
use Ledger\Domain\Transfer\TransferRepository;
use Ledger\Domain\Transfer\TransferTerms;
use Ledger\Infrastructure\Projection\ProjectionRunner;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Fixtures as F;
use Tests\TestCase;

final class ResumeTransfersTest extends TestCase
{
    /**
     * Leaves a transfer exactly as a crash after the source debit would:
     * money left Alice, Bob has not received it yet.
     */
    private function crashAfterSourceDebit(): void
    {
        $this->bus()->dispatch(new OpenAccount(F::alice(), 'Alice', 'EUR', 0));
        $this->bus()->dispatch(new OpenAccount(F::bob(), 'Bob', 'EUR', 0));
        $this->bus()->dispatch(new DepositMoney(F::alice(), F::transactionId(), 1_000, 'EUR'));

        $transfers = $this->app->make(TransferRepository::class);
        $accounts = $this->app->make(AccountRepository::class);

        $transfer = Transfer::initiate(F::transferId(), new TransferTerms(F::alice(), F::bob(), F::eur(250)));
        $transfers->save($transfer);
        $alice = $accounts->get(F::alice());
        $alice->debitForTransfer(F::transferId(), F::eur(250), F::bob());
        $accounts->save($alice);
        $transfer->markSourceDebited();
        $transfers->save($transfer);

        $this->app->make(ProjectionRunner::class)->runAll();
    }

    #[Test]
    public function stalled_transfers_are_driven_to_completion(): void
    {
        $this->crashAfterSourceDebit();
        $this->clock->advance('+5 minutes');

        $this->command('ledger:transfers:resume', ['--older-than' => 60])
            ->expectsOutput(F::TRANSFER.' -> completed')
            ->expectsOutput('Resumed 1 of 1 stalled transfer(s).')
            ->assertSuccessful();

        self::assertSame('completed', DB::table('transfers')->value('status'));
        self::assertSame(250, self::int(DB::table('account_balances')->where('account_id', F::BOB)->value('balance')));
        self::assertSame(750, self::int(DB::table('account_balances')->where('account_id', F::ALICE)->value('balance')));
    }

    #[Test]
    public function transfers_still_within_the_grace_period_are_left_alone(): void
    {
        $this->crashAfterSourceDebit();
        $this->clock->advance('+10 seconds');

        $this->command('ledger:transfers:resume', ['--older-than' => 60])
            ->expectsOutput('Resumed 0 of 0 stalled transfer(s).')
            ->assertSuccessful();

        self::assertSame('source_debited', DB::table('transfers')->value('status'));
    }
}
