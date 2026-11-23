<?php

declare(strict_types=1);

namespace Tests\Unit\Application;

use Ledger\Application\Account\FreezeAccount;
use Ledger\Application\Transfer\TransferMoney;
use Ledger\Domain\Account\AccountId;
use Ledger\Domain\Account\Event\TransferCredited;
use Ledger\Domain\Account\Event\TransferDebited;
use Ledger\Domain\Account\Event\TransferDebitReversed;
use Ledger\Domain\Account\Exception\AccountNotFound;
use Ledger\Domain\Shared\TransactionId;
use Ledger\Domain\Transfer\Event\TransferCompensated;
use Ledger\Domain\Transfer\Event\TransferCompleted;
use Ledger\Domain\Transfer\Event\TransferCreditFailed;
use Ledger\Domain\Transfer\Event\TransferInitiated;
use Ledger\Domain\Transfer\Event\TransferRejected;
use Ledger\Domain\Transfer\Event\TransferSourceDebited;
use Ledger\Domain\Transfer\Exception\InvalidTransfer;
use Ledger\Domain\Transfer\Exception\TransferNotFound;
use Ledger\Domain\Transfer\Transfer;
use Ledger\Domain\Transfer\TransferStatus;
use Ledger\Domain\Transfer\TransferTerms;
use Ledger\EventSourcing\EventEnvelope;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Support\Fixtures as F;
use Tests\Support\InMemoryLedger;

final class TransferProcessManagerTest extends TestCase
{
    private InMemoryLedger $ledger;

    protected function setUp(): void
    {
        $this->ledger = new InMemoryLedger;
        $this->ledger->openAccount(F::alice(), deposit: 1_000);
        $this->ledger->openAccount(F::bob());
    }

    private function transfer(int $amount, string $currency = 'EUR'): void
    {
        $this->ledger->bus->dispatch(new TransferMoney(F::transferId(), F::alice(), F::bob(), $amount, $currency));
    }

    private function transferState(): Transfer
    {
        $transfer = $this->ledger->transfers->find(F::transferId());
        self::assertNotNull($transfer);

        return $transfer;
    }

    /**
     * @return list<class-string>
     */
    private function eventsOfStream(string $streamId): array
    {
        return array_map(fn (EventEnvelope $e) => $e->event::class, $this->ledger->events->load($streamId));
    }

    #[Test]
    public function a_transfer_debits_the_source_and_credits_the_destination(): void
    {
        $this->transfer(400);

        self::assertSame(600, $this->ledger->balanceOf(F::alice()));
        self::assertSame(400, $this->ledger->balanceOf(F::bob()));
        self::assertSame(TransferStatus::Completed, $this->transferState()->status());
        self::assertSame(
            [TransferInitiated::class, TransferSourceDebited::class, TransferCompleted::class],
            $this->eventsOfStream('transfer-'.F::TRANSFER),
        );
    }

    #[Test]
    public function the_saga_steps_are_written_in_causal_order(): void
    {
        $this->transfer(400);

        $all = $this->ledger->events->loadFromPosition(0, 100);
        $sagaEvents = array_values(array_filter(
            array_map(fn (EventEnvelope $e) => $e->event::class, $all),
            fn (string $class) => str_contains($class, 'Transfer'),
        ));

        self::assertSame([
            TransferInitiated::class,
            TransferDebited::class,
            TransferSourceDebited::class,
            TransferCredited::class,
            TransferCompleted::class,
        ], $sagaEvents);

        // Every step is caused by the event written just before it.
        $transferSteps = array_slice($all, -5);
        foreach (array_slice($transferSteps, 1) as $i => $step) {
            self::assertSame($transferSteps[$i]->eventId(), $step->metadata->causationId);
            self::assertSame($transferSteps[0]->metadata->correlationId, $step->metadata->correlationId);
        }
    }

    #[Test]
    public function insufficient_funds_reject_the_transfer_and_nothing_moves(): void
    {
        $this->transfer(1_001);

        $transfer = $this->transferState();
        self::assertSame(TransferStatus::Rejected, $transfer->status());
        self::assertStringContainsString('cannot be debited', (string) $transfer->failureReason());
        self::assertSame(1_000, $this->ledger->balanceOf(F::alice()));
        self::assertSame(0, $this->ledger->balanceOf(F::bob()));
        self::assertSame([TransferInitiated::class, TransferRejected::class], $this->eventsOfStream('transfer-'.F::TRANSFER));
    }

    #[Test]
    public function a_frozen_destination_triggers_compensation(): void
    {
        $this->ledger->bus->dispatch(new FreezeAccount(F::bob(), 'sanctions screening'));

        $this->transfer(400);

        $transfer = $this->transferState();
        self::assertSame(TransferStatus::Compensated, $transfer->status());
        self::assertStringContainsString('frozen', (string) $transfer->failureReason());
        self::assertSame(1_000, $this->ledger->balanceOf(F::alice()), 'The source got its money back.');
        self::assertSame(0, $this->ledger->balanceOf(F::bob()));
        self::assertSame(
            [TransferInitiated::class, TransferSourceDebited::class, TransferCreditFailed::class, TransferCompensated::class],
            $this->eventsOfStream('transfer-'.F::TRANSFER),
        );
        self::assertContains(TransferDebitReversed::class, $this->eventsOfStream('account-'.F::ALICE));
    }

    #[Test]
    public function a_destination_in_another_currency_is_also_compensated(): void
    {
        $usd = AccountId::generate();
        $this->ledger->openAccount($usd, currency: 'USD');

        $this->ledger->bus->dispatch(new TransferMoney(F::transferId(), F::alice(), $usd, 400, 'EUR'));

        self::assertSame(TransferStatus::Compensated, $this->transferState()->status());
        self::assertSame(1_000, $this->ledger->balanceOf(F::alice()));
    }

    #[Test]
    public function both_accounts_must_exist_before_anything_is_written(): void
    {
        $unknown = AccountId::generate();

        try {
            $this->ledger->bus->dispatch(new TransferMoney(F::transferId(), F::alice(), $unknown, 100, 'EUR'));
            self::fail('Expected an unknown destination to be refused.');
        } catch (AccountNotFound) {
            self::assertNull($this->ledger->transfers->find(F::transferId()));
        }
    }

    #[Test]
    public function retrying_the_same_transfer_is_idempotent(): void
    {
        $this->transfer(400);
        $this->transfer(400);

        self::assertSame(600, $this->ledger->balanceOf(F::alice()));
        self::assertSame(400, $this->ledger->balanceOf(F::bob()));
        self::assertCount(3, $this->ledger->events->load('transfer-'.F::TRANSFER));
    }

    #[Test]
    public function reusing_a_transfer_id_with_different_terms_is_refused(): void
    {
        $this->transfer(400);

        $this->expectException(InvalidTransfer::class);

        $this->transfer(300);
    }

    #[Test]
    public function a_saga_interrupted_after_the_debit_resumes_without_debiting_twice(): void
    {
        // Simulate a crash: the source was debited and the transfer knows it,
        // but the process died before crediting the destination.
        $terms = new TransferTerms(F::alice(), F::bob(), F::eur(400));
        $transfer = Transfer::initiate(F::transferId(), $terms);
        $alice = $this->ledger->accounts->get(F::alice());
        $alice->debitForTransfer(F::transferId(), F::eur(400), F::bob());
        $this->ledger->accounts->save($alice);
        $transfer->markSourceDebited();
        $this->ledger->transfers->save($transfer);

        $resumed = $this->ledger->processManager->resume(F::transferId());

        self::assertSame(TransferStatus::Completed, $resumed->status());
        self::assertSame(600, $this->ledger->balanceOf(F::alice()));
        self::assertSame(400, $this->ledger->balanceOf(F::bob()));
    }

    #[Test]
    public function a_crash_between_the_account_debit_and_the_transfer_update_is_harmless(): void
    {
        // The account was debited but the transfer still says "initiated":
        // resuming re-runs the debit step, which the account recognises.
        $transfer = Transfer::initiate(F::transferId(), new TransferTerms(F::alice(), F::bob(), F::eur(400)));
        $this->ledger->transfers->save($transfer);
        $alice = $this->ledger->accounts->get(F::alice());
        $alice->debitForTransfer(F::transferId(), F::eur(400), F::bob());
        $this->ledger->accounts->save($alice);

        $this->ledger->processManager->resume(F::transferId());

        self::assertSame(600, $this->ledger->balanceOf(F::alice()));
        self::assertSame(400, $this->ledger->balanceOf(F::bob()));
        self::assertSame(1, array_count_values($this->eventsOfStream('account-'.F::ALICE))[TransferDebited::class]);
    }

    #[Test]
    public function resuming_an_unknown_transfer_fails(): void
    {
        $this->expectException(TransferNotFound::class);

        $this->ledger->processManager->resume(F::transferId());
    }

    #[Test]
    public function a_concurrent_write_on_the_source_during_the_saga_is_retried(): void
    {
        // A deposit sneaks in right before the saga's first append (the transfer itself).
        // The transfer stream is unaffected; then a second deposit races the account debit.
        $this->ledger->store->interleaveBeforeNextAppend(fn () => null);
        $this->ledger->store->interleaveBeforeNextAppend(function (): void {
            $other = $this->ledger->otherWriter();
            $alice = $other->get(F::alice());
            $alice->deposit(TransactionId::generate(), F::eur(1));
            $other->save($alice);
        });

        $this->transfer(400);

        self::assertSame(TransferStatus::Completed, $this->transferState()->status());
        self::assertSame(601, $this->ledger->balanceOf(F::alice()));
        self::assertSame(400, $this->ledger->balanceOf(F::bob()));
    }
}
