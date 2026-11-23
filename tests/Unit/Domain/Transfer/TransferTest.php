<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Transfer;

use Ledger\Domain\Shared\InvalidAmount;
use Ledger\Domain\Transfer\Event\TransferCompensated;
use Ledger\Domain\Transfer\Event\TransferCompleted;
use Ledger\Domain\Transfer\Event\TransferCreditFailed;
use Ledger\Domain\Transfer\Event\TransferInitiated;
use Ledger\Domain\Transfer\Event\TransferRejected;
use Ledger\Domain\Transfer\Event\TransferSourceDebited;
use Ledger\Domain\Transfer\Exception\IllegalTransferTransition;
use Ledger\Domain\Transfer\Exception\InvalidTransfer;
use Ledger\Domain\Transfer\Transfer;
use Ledger\Domain\Transfer\TransferStatus;
use Ledger\Domain\Transfer\TransferTerms;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Support\AggregateScenario;
use Tests\Support\Fixtures as F;

final class TransferTest extends TestCase
{
    private static function terms(): TransferTerms
    {
        return new TransferTerms(F::alice(), F::bob(), F::eur(400));
    }

    /**
     * @return AggregateScenario<Transfer>
     */
    private function scenario(): AggregateScenario
    {
        return AggregateScenario::for(Transfer::class);
    }

    #[Test]
    public function initiating_records_the_terms(): void
    {
        $transfer = $this->scenario()
            ->whenCreating(fn () => Transfer::initiate(F::transferId(), self::terms()))
            ->then(new TransferInitiated(F::transferId(), self::terms()));

        self::assertSame(TransferStatus::Initiated, $transfer->status());
    }

    #[Test]
    public function money_cannot_be_transferred_to_the_same_account(): void
    {
        $this->expectException(InvalidTransfer::class);

        new TransferTerms(F::alice(), F::alice(), F::eur(100));
    }

    #[Test]
    public function the_amount_must_be_positive(): void
    {
        $this->expectException(InvalidAmount::class);

        new TransferTerms(F::alice(), F::bob(), F::eur(0));
    }

    #[Test]
    public function the_happy_path_goes_initiated_debited_completed(): void
    {
        $this->scenario()
            ->given(new TransferInitiated(F::transferId(), self::terms()))
            ->when(fn (Transfer $t) => $t->markSourceDebited())
            ->then(new TransferSourceDebited(F::transferId()));

        $completed = $this->scenario()
            ->given(new TransferInitiated(F::transferId(), self::terms()), new TransferSourceDebited(F::transferId()))
            ->when(fn (Transfer $t) => $t->complete())
            ->then(new TransferCompleted(F::transferId(), self::terms()));

        self::assertTrue($completed->status()->isFinal());
    }

    #[Test]
    public function a_refused_debit_rejects_the_transfer(): void
    {
        $transfer = $this->scenario()
            ->given(new TransferInitiated(F::transferId(), self::terms()))
            ->when(fn (Transfer $t) => $t->reject('insufficient funds'))
            ->then(new TransferRejected(F::transferId(), self::terms(), 'insufficient funds'));

        self::assertSame('insufficient funds', $transfer->failureReason());
        self::assertTrue($transfer->status()->isFinal());
    }

    #[Test]
    public function it_cannot_complete_before_the_source_was_debited(): void
    {
        $this->scenario()
            ->given(new TransferInitiated(F::transferId(), self::terms()))
            ->when(fn (Transfer $t) => $t->complete())
            ->thenFails(IllegalTransferTransition::class, 'cannot complete while initiated');
    }

    #[Test]
    public function a_failed_credit_leads_to_compensation_keeping_the_reason(): void
    {
        $history = [
            new TransferInitiated(F::transferId(), self::terms()),
            new TransferSourceDebited(F::transferId()),
        ];

        $this->scenario()->given(...$history)
            ->when(fn (Transfer $t) => $t->markCreditFailed('destination frozen'))
            ->then(new TransferCreditFailed(F::transferId(), 'destination frozen'));

        $compensated = $this->scenario()
            ->given(...$history, ...[new TransferCreditFailed(F::transferId(), 'destination frozen')])
            ->when(fn (Transfer $t) => $t->markCompensated())
            ->then(new TransferCompensated(F::transferId(), self::terms(), 'destination frozen'));

        self::assertSame(TransferStatus::Compensated, $compensated->status());
    }

    #[Test]
    public function compensation_is_only_possible_after_a_failed_credit(): void
    {
        $this->scenario()
            ->given(new TransferInitiated(F::transferId(), self::terms()), new TransferSourceDebited(F::transferId()))
            ->when(fn (Transfer $t) => $t->markCompensated())
            ->thenFails(IllegalTransferTransition::class);
    }

    #[Test]
    public function a_completed_transfer_is_immutable(): void
    {
        $history = [
            new TransferInitiated(F::transferId(), self::terms()),
            new TransferSourceDebited(F::transferId()),
            new TransferCompleted(F::transferId(), self::terms()),
        ];

        foreach ([
            fn (Transfer $t) => $t->reject('late'),
            fn (Transfer $t) => $t->markCreditFailed('late'),
            fn (Transfer $t) => $t->complete(),
        ] as $behaviour) {
            $this->scenario()->given(...$history)->when($behaviour)->thenFails(IllegalTransferTransition::class);
        }
    }

    #[Test]
    public function only_terminal_states_are_final(): void
    {
        $final = array_values(array_filter(TransferStatus::cases(), fn (TransferStatus $s) => $s->isFinal()));

        self::assertSame([TransferStatus::Completed, TransferStatus::Rejected, TransferStatus::Compensated], $final);
    }
}
