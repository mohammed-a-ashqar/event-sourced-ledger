<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Account;

use Ledger\Domain\Account\Account;
use Ledger\Domain\Account\AccountStatus;
use Ledger\Domain\Account\Event\AccountFrozen;
use Ledger\Domain\Account\Event\AccountOpened;
use Ledger\Domain\Account\Event\AccountUnfrozen;
use Ledger\Domain\Account\Event\MoneyDeposited;
use Ledger\Domain\Account\Event\MoneyWithdrawn;
use Ledger\Domain\Account\Event\TransferCredited;
use Ledger\Domain\Account\Event\TransferDebited;
use Ledger\Domain\Account\Event\TransferDebitReversed;
use Ledger\Domain\Account\Exception\AccountIsFrozen;
use Ledger\Domain\Account\Exception\AccountIsNotFrozen;
use Ledger\Domain\Account\Exception\InsufficientFunds;
use Ledger\Domain\Account\Exception\InvalidAccountDetails;
use Ledger\Domain\Account\Exception\TransferLegMismatch;
use Ledger\Domain\Shared\Currency;
use Ledger\Domain\Shared\CurrencyMismatch;
use Ledger\Domain\Shared\InvalidAmount;
use Ledger\Domain\Shared\Money;
use Ledger\EventSourcing\MalformedPayload;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Support\AggregateScenario;
use Tests\Support\Fixtures as F;

final class AccountTest extends TestCase
{
    /**
     * @return AggregateScenario<Account>
     */
    private function scenario(): AggregateScenario
    {
        return AggregateScenario::for(Account::class);
    }

    #[Test]
    public function opening_an_account_records_the_owner_currency_and_overdraft(): void
    {
        $this->scenario()
            ->whenCreating(fn () => Account::open(F::alice(), '  Alice  ', Currency::of('EUR'), F::eur(5_000)))
            ->then(new AccountOpened(F::alice(), 'Alice', Currency::of('EUR'), F::eur(5_000)));
    }

    #[Test]
    public function an_owner_name_is_required(): void
    {
        $this->scenario()
            ->whenCreating(fn () => Account::open(F::alice(), '   ', Currency::of('EUR'), F::eur(0)))
            ->thenFails(InvalidAccountDetails::class, 'Owner name');
    }

    #[Test]
    public function the_overdraft_limit_cannot_be_negative(): void
    {
        $this->scenario()
            ->whenCreating(fn () => Account::open(F::alice(), 'Alice', Currency::of('EUR'), F::eur(-1)))
            ->thenFails(InvalidAmount::class);
    }

    #[Test]
    public function the_overdraft_limit_must_be_in_the_account_currency(): void
    {
        $this->scenario()
            ->whenCreating(fn () => Account::open(F::alice(), 'Alice', Currency::of('EUR'), Money::of(100, 'USD')))
            ->thenFails(CurrencyMismatch::class);
    }

    #[Test]
    public function depositing_records_the_amount_and_reference(): void
    {
        $this->scenario()
            ->given(F::opened())
            ->when(fn (Account $a) => $a->deposit(F::transactionId(), F::eur(2_500), 'salary'))
            ->then(new MoneyDeposited(F::alice(), F::transactionId(), F::eur(2_500), 'salary'));
    }

    #[Test]
    public function zero_or_negative_amounts_cannot_be_deposited(): void
    {
        foreach ([0, -100] as $amount) {
            $this->scenario()
                ->given(F::opened())
                ->when(fn (Account $a) => $a->deposit(F::transactionId(), F::eur($amount)))
                ->thenFails(InvalidAmount::class, 'must be positive');
        }
    }

    #[Test]
    public function amounts_in_another_currency_are_refused(): void
    {
        $this->scenario()
            ->given(F::opened())
            ->when(fn (Account $a) => $a->deposit(F::transactionId(), Money::of(100, 'USD')))
            ->thenFails(CurrencyMismatch::class);
    }

    #[Test]
    public function withdrawing_within_the_balance_is_allowed(): void
    {
        $this->scenario()
            ->given(F::opened(), F::deposited(1_000))
            ->when(fn (Account $a) => $a->withdraw(F::transactionId(), F::eur(1_000)))
            ->then(new MoneyWithdrawn(F::alice(), F::transactionId(), F::eur(1_000), null));
    }

    #[Test]
    public function withdrawing_more_than_the_balance_without_overdraft_is_refused(): void
    {
        $this->scenario()
            ->given(F::opened(), F::deposited(1_000))
            ->when(fn (Account $a) => $a->withdraw(F::transactionId(), F::eur(1_001)))
            ->thenFails(InsufficientFunds::class, 'only 10.00 EUR is available');
    }

    #[Test]
    public function the_overdraft_can_be_used_up_to_the_exact_limit(): void
    {
        $account = $this->scenario()
            ->given(F::opened(overdraftLimit: 500), F::deposited(1_000))
            ->when(fn (Account $a) => $a->withdraw(F::transactionId(), F::eur(1_500)))
            ->then(new MoneyWithdrawn(F::alice(), F::transactionId(), F::eur(1_500), null));

        self::assertSame(-500, $account->balance()->amount);
        self::assertTrue($account->available()->isZero());
    }

    #[Test]
    public function going_beyond_the_overdraft_limit_is_refused(): void
    {
        $this->scenario()
            ->given(F::opened(overdraftLimit: 500), F::deposited(1_000))
            ->when(fn (Account $a) => $a->withdraw(F::transactionId(), F::eur(1_501)))
            ->thenFails(InsufficientFunds::class);
    }

    #[Test]
    public function a_frozen_account_accepts_neither_deposits_nor_withdrawals(): void
    {
        $history = [F::opened(), F::deposited(1_000), new AccountFrozen(F::alice(), 'KYC review')];

        $this->scenario()->given(...$history)
            ->when(fn (Account $a) => $a->deposit(F::transactionId(), F::eur(1)))
            ->thenFails(AccountIsFrozen::class);

        $this->scenario()->given(...$history)
            ->when(fn (Account $a) => $a->withdraw(F::transactionId(), F::eur(1)))
            ->thenFails(AccountIsFrozen::class);
    }

    #[Test]
    public function freezing_requires_a_reason_and_an_active_account(): void
    {
        $this->scenario()->given(F::opened())
            ->when(fn (Account $a) => $a->freeze(' '))
            ->thenFails(InvalidAccountDetails::class);

        $this->scenario()->given(F::opened(), new AccountFrozen(F::alice(), 'fraud'))
            ->when(fn (Account $a) => $a->freeze('again'))
            ->thenFails(AccountIsFrozen::class);
    }

    #[Test]
    public function freezing_records_the_reason(): void
    {
        $account = $this->scenario()->given(F::opened())
            ->when(fn (Account $a) => $a->freeze('KYC review'))
            ->then(new AccountFrozen(F::alice(), 'KYC review'));

        self::assertSame(AccountStatus::Frozen, $account->status());
    }

    #[Test]
    public function only_a_frozen_account_can_be_unfrozen(): void
    {
        $this->scenario()->given(F::opened())
            ->when(fn (Account $a) => $a->unfreeze())
            ->thenFails(AccountIsNotFrozen::class);
    }

    #[Test]
    public function an_unfrozen_account_operates_again(): void
    {
        $this->scenario()
            ->given(F::opened(), new AccountFrozen(F::alice(), 'review'), new AccountUnfrozen(F::alice()))
            ->when(fn (Account $a) => $a->deposit(F::transactionId(), F::eur(100)))
            ->then(new MoneyDeposited(F::alice(), F::transactionId(), F::eur(100), null));
    }

    #[Test]
    public function a_transfer_debit_reduces_the_balance(): void
    {
        $account = $this->scenario()
            ->given(F::opened(), F::deposited(1_000))
            ->when(fn (Account $a) => $a->debitForTransfer(F::transferId(), F::eur(400), F::bob()))
            ->then(new TransferDebited(F::alice(), F::transferId(), F::eur(400), F::bob()));

        self::assertSame(600, $account->balance()->amount);
    }

    #[Test]
    public function the_same_transfer_is_never_debited_twice(): void
    {
        $this->scenario()
            ->given(F::opened(), F::deposited(1_000), new TransferDebited(F::alice(), F::transferId(), F::eur(400), F::bob()))
            ->when(fn (Account $a) => $a->debitForTransfer(F::transferId(), F::eur(400), F::bob()))
            ->thenNothingHappened();
    }

    #[Test]
    public function replaying_a_transfer_leg_with_a_different_amount_is_an_error(): void
    {
        $this->scenario()
            ->given(F::opened(), F::deposited(1_000), new TransferDebited(F::alice(), F::transferId(), F::eur(400), F::bob()))
            ->when(fn (Account $a) => $a->debitForTransfer(F::transferId(), F::eur(401), F::bob()))
            ->thenFails(TransferLegMismatch::class);
    }

    #[Test]
    public function a_transfer_debit_respects_available_funds(): void
    {
        $this->scenario()
            ->given(F::opened(), F::deposited(100))
            ->when(fn (Account $a) => $a->debitForTransfer(F::transferId(), F::eur(101), F::bob()))
            ->thenFails(InsufficientFunds::class);
    }

    #[Test]
    public function a_frozen_account_cannot_receive_a_transfer(): void
    {
        $this->scenario()
            ->given(F::opened(F::bob()), new AccountFrozen(F::bob(), 'sanctions'))
            ->when(fn (Account $a) => $a->creditFromTransfer(F::transferId(), F::eur(100), F::alice()))
            ->thenFails(AccountIsFrozen::class);
    }

    #[Test]
    public function a_transfer_credit_is_applied_once(): void
    {
        $credited = new TransferCredited(F::bob(), F::transferId(), F::eur(100), F::alice());

        $this->scenario()
            ->given(F::opened(F::bob()))
            ->when(fn (Account $a) => $a->creditFromTransfer(F::transferId(), F::eur(100), F::alice()))
            ->then($credited);

        $this->scenario()
            ->given(F::opened(F::bob()), $credited)
            ->when(fn (Account $a) => $a->creditFromTransfer(F::transferId(), F::eur(100), F::alice()))
            ->thenNothingHappened();
    }

    #[Test]
    public function only_a_debited_transfer_can_be_reversed(): void
    {
        $this->scenario()
            ->given(F::opened(), F::deposited(1_000))
            ->when(fn (Account $a) => $a->reverseTransferDebit(F::transferId(), F::eur(400), F::bob()))
            ->thenFails(TransferLegMismatch::class, 'never debited');
    }

    #[Test]
    public function a_reversal_refunds_even_a_frozen_account_and_only_once(): void
    {
        $history = [
            F::opened(),
            F::deposited(1_000),
            new TransferDebited(F::alice(), F::transferId(), F::eur(400), F::bob()),
            new AccountFrozen(F::alice(), 'review'),
        ];
        $reversed = new TransferDebitReversed(F::alice(), F::transferId(), F::eur(400), F::bob());

        $account = $this->scenario()->given(...$history)
            ->when(fn (Account $a) => $a->reverseTransferDebit(F::transferId(), F::eur(400), F::bob()))
            ->then($reversed);

        self::assertSame(1_000, $account->balance()->amount);

        $this->scenario()->given(...$history, ...[$reversed])
            ->when(fn (Account $a) => $a->reverseTransferDebit(F::transferId(), F::eur(400), F::bob()))
            ->thenNothingHappened();
    }

    #[Test]
    public function state_is_rebuilt_from_history(): void
    {
        $account = Account::reconstituteFromHistory([
            F::opened(overdraftLimit: 300),
            F::deposited(1_000),
            new MoneyWithdrawn(F::alice(), F::transactionId(), F::eur(250), null),
            new TransferDebited(F::alice(), F::transferId(), F::eur(100), F::bob()),
        ]);

        self::assertSame(650, $account->balance()->amount);
        self::assertSame(950, $account->available()->amount);
        self::assertSame(4, $account->version());
        self::assertSame(4, $account->persistedVersion());
        self::assertSame('Alice', $account->ownerName());
        self::assertSame('EUR', $account->currency()->code);
    }

    #[Test]
    public function a_snapshot_restores_exactly_the_replayed_state(): void
    {
        $replayed = Account::reconstituteFromHistory([
            F::opened(overdraftLimit: 300),
            F::deposited(1_000),
            new TransferDebited(F::alice(), F::transferId(), F::eur(100), F::bob()),
            new AccountFrozen(F::alice(), 'review'),
        ]);

        $restored = Account::restoreFromSnapshot($replayed->snapshotState(), $replayed->version());

        self::assertEquals($replayed->snapshotState(), $restored->snapshotState());
        self::assertSame(4, $restored->version());
        self::assertSame([], $restored->pendingEvents());

        // The restored transfer bookkeeping still protects against a double debit.
        $restored->reverseTransferDebit(F::transferId(), F::eur(100), F::bob());
        self::assertSame(1_000, $restored->balance()->amount);
    }

    #[Test]
    public function a_corrupted_snapshot_is_rejected(): void
    {
        $state = Account::reconstituteFromHistory([F::opened()])->snapshotState();
        $state['transfer_legs'] = ['account.transfer_debited:x' => '100'];

        $this->expectException(MalformedPayload::class);

        Account::restoreFromSnapshot($state, 1);
    }
}
