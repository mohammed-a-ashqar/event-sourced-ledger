<?php

declare(strict_types=1);

namespace Tests\Unit\Application;

use DateTimeImmutable;
use Ledger\Application\Account\DepositMoney;
use Ledger\Application\Account\FreezeAccount;
use Ledger\Application\Account\OpenAccount;
use Ledger\Application\Account\UnfreezeAccount;
use Ledger\Application\Account\WithdrawMoney;
use Ledger\Application\Query\BalanceAsOf;
use Ledger\Domain\Account\AccountStatus;
use Ledger\Domain\Account\Exception\AccountAlreadyExists;
use Ledger\Domain\Account\Exception\AccountNotFound;
use Ledger\Domain\Account\Exception\InsufficientFunds;
use Ledger\Domain\Shared\TransactionId;
use Ledger\Domain\Shared\UnsupportedCurrency;
use Ledger\EventSourcing\ConcurrencyException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Support\Fixtures as F;
use Tests\Support\InMemoryLedger;

final class AccountCommandsTest extends TestCase
{
    private InMemoryLedger $ledger;

    protected function setUp(): void
    {
        $this->ledger = new InMemoryLedger;
    }

    #[Test]
    public function an_account_is_opened_deposited_into_and_withdrawn_from(): void
    {
        $this->ledger->bus->dispatch(new OpenAccount(F::alice(), 'Alice', 'EUR', 0));
        $this->ledger->bus->dispatch(new DepositMoney(F::alice(), TransactionId::generate(), 10_000, 'EUR', 'salary'));
        $this->ledger->bus->dispatch(new WithdrawMoney(F::alice(), TransactionId::generate(), 2_500, 'EUR'));

        self::assertSame(7_500, $this->ledger->balanceOf(F::alice()));
        self::assertCount(3, $this->ledger->events->load('account-'.F::ALICE));
    }

    #[Test]
    public function the_same_account_cannot_be_opened_twice(): void
    {
        $this->ledger->openAccount(F::alice());

        $this->expectException(AccountAlreadyExists::class);

        $this->ledger->bus->dispatch(new OpenAccount(F::alice(), 'Alice again', 'EUR', 0));
    }

    #[Test]
    public function commands_against_an_unknown_account_fail(): void
    {
        $this->expectException(AccountNotFound::class);

        $this->ledger->bus->dispatch(new DepositMoney(F::alice(), TransactionId::generate(), 100, 'EUR'));
    }

    #[Test]
    public function unsupported_currencies_are_rejected_before_anything_is_written(): void
    {
        try {
            $this->ledger->bus->dispatch(new OpenAccount(F::alice(), 'Alice', 'XYZ', 0));
            self::fail('Expected an unsupported currency.');
        } catch (UnsupportedCurrency) {
            self::assertSame([], $this->ledger->events->loadFromPosition(0, 10));
        }
    }

    #[Test]
    public function freezing_and_unfreezing_change_the_status(): void
    {
        $this->ledger->openAccount(F::alice());

        $this->ledger->bus->dispatch(new FreezeAccount(F::alice(), 'KYC review'));
        self::assertSame(AccountStatus::Frozen, $this->ledger->accounts->get(F::alice())->status());

        $this->ledger->bus->dispatch(new UnfreezeAccount(F::alice()));
        self::assertSame(AccountStatus::Active, $this->ledger->accounts->get(F::alice())->status());
    }

    #[Test]
    public function a_command_that_loses_a_race_is_retried_against_fresh_state(): void
    {
        $this->ledger->openAccount(F::alice(), deposit: 100);

        $this->ledger->store->interleaveBeforeNextAppend(function (): void {
            $other = $this->ledger->otherWriter();
            $account = $other->get(F::alice());
            $account->deposit(TransactionId::generate(), F::eur(5));
            $other->save($account);
        });

        $this->ledger->bus->dispatch(new DepositMoney(F::alice(), TransactionId::generate(), 10, 'EUR'));

        self::assertSame(115, $this->ledger->balanceOf(F::alice()), 'Both deposits survive; none is lost.');
    }

    #[Test]
    public function a_retry_re_checks_invariants_so_a_race_cannot_overdraw_the_account(): void
    {
        $this->ledger->openAccount(F::alice(), deposit: 100);

        // Another writer withdraws 50 between our load (balance 100) and our append.
        $this->ledger->store->interleaveBeforeNextAppend(function (): void {
            $other = $this->ledger->otherWriter();
            $account = $other->get(F::alice());
            $account->withdraw(TransactionId::generate(), F::eur(50));
            $other->save($account);
        });

        try {
            $this->ledger->bus->dispatch(new WithdrawMoney(F::alice(), TransactionId::generate(), 80, 'EUR'));
            self::fail('The retried withdrawal must see the competing one and be refused.');
        } catch (InsufficientFunds) {
            self::assertSame(50, $this->ledger->balanceOf(F::alice()));
        }
    }

    #[Test]
    public function a_conflict_surfaces_once_the_attempts_are_used_up(): void
    {
        $this->ledger->openAccount(F::alice(), deposit: 100);

        for ($i = 0; $i < 3; $i++) {
            $this->ledger->store->interleaveBeforeNextAppend(function (): void {
                $other = $this->ledger->otherWriter();
                $account = $other->get(F::alice());
                $account->deposit(TransactionId::generate(), F::eur(1));
                $other->save($account);
            });
        }

        $this->expectException(ConcurrencyException::class);

        $this->ledger->bus->dispatch(new DepositMoney(F::alice(), TransactionId::generate(), 10, 'EUR'));
    }

    #[Test]
    public function the_balance_can_be_queried_as_of_any_past_moment(): void
    {
        $this->ledger->clock->setTo('2026-03-01 09:00:00');
        $this->ledger->openAccount(F::alice());
        $this->ledger->clock->setTo('2026-03-05 12:00:00');
        $this->ledger->bus->dispatch(new DepositMoney(F::alice(), TransactionId::generate(), 1_000, 'EUR'));
        $this->ledger->clock->setTo('2026-03-10 12:00:00');
        $this->ledger->bus->dispatch(new WithdrawMoney(F::alice(), TransactionId::generate(), 300, 'EUR'));

        $balanceAsOf = new BalanceAsOf($this->ledger->accounts);
        $at = static fn (string $moment) => new DateTimeImmutable($moment.' UTC');

        self::assertNull($balanceAsOf(F::alice(), $at('2026-02-28 00:00:00')));
        self::assertSame(0, $balanceAsOf(F::alice(), $at('2026-03-04 00:00:00'))?->amount);
        self::assertSame(1_000, $balanceAsOf(F::alice(), $at('2026-03-07 00:00:00'))?->amount);
        self::assertSame(700, $balanceAsOf(F::alice(), $at('2026-03-11 00:00:00'))?->amount);
    }

    #[Test]
    public function a_temporal_query_for_an_unknown_account_fails(): void
    {
        $this->expectException(AccountNotFound::class);

        (new BalanceAsOf($this->ledger->accounts))(F::bob(), new DateTimeImmutable);
    }
}
