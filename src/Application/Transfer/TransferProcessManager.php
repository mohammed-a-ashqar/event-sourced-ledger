<?php

declare(strict_types=1);

namespace Ledger\Application\Transfer;

use Closure;
use Ledger\Application\Bus\ConcurrencyRetry;
use Ledger\Domain\Account\Account;
use Ledger\Domain\Account\AccountId;
use Ledger\Domain\Account\AccountRepository;
use Ledger\Domain\Shared\BusinessRuleViolation;
use Ledger\Domain\Transfer\Exception\InvalidTransfer;
use Ledger\Domain\Transfer\Exception\TransferNotFound;
use Ledger\Domain\Transfer\Transfer;
use Ledger\Domain\Transfer\TransferId;
use Ledger\Domain\Transfer\TransferRepository;
use Ledger\Domain\Transfer\TransferStatus;
use Ledger\Domain\Transfer\TransferTerms;

/**
 * Drives a transfer across two account aggregates (a saga).
 *
 *   1. debit the source       -> refused? the transfer is rejected, nothing moved
 *   2. credit the destination -> refused? record it and go to 3
 *   3. reverse the debit      (compensation)
 *
 * Every step is its own atomic append, and every account step is idempotent
 * per transfer id, so advance() can be re-run at any point after a crash
 * (see the ledger:transfers:resume command) without moving money twice.
 */
final readonly class TransferProcessManager
{
    public function __construct(
        private TransferRepository $transfers,
        private AccountRepository $accounts,
        private ConcurrencyRetry $retry,
    ) {}

    public function start(TransferId $id, TransferTerms $terms): Transfer
    {
        $transfer = $this->transfers->find($id);

        if ($transfer === null) {
            $transfer = Transfer::initiate($id, $terms);
            $this->transfers->save($transfer);
        } elseif (! $transfer->terms()->equals($terms)) {
            throw InvalidTransfer::conflictingRetry($id);
        }

        return $this->advance($transfer);
    }

    public function resume(TransferId $id): Transfer
    {
        return $this->advance($this->transfers->find($id) ?? throw TransferNotFound::withId($id));
    }

    private function advance(Transfer $transfer): Transfer
    {
        while (($step = $this->nextStep($transfer->status())) !== null) {
            $step($transfer);
            $this->transfers->save($transfer);
        }

        return $transfer;
    }

    /**
     * @return (Closure(Transfer): void)|null null once the saga reached a final state
     */
    private function nextStep(TransferStatus $status): ?Closure
    {
        return match ($status) {
            TransferStatus::Initiated => $this->debitSource(...),
            TransferStatus::SourceDebited => $this->creditDestination(...),
            TransferStatus::CreditFailed => $this->compensate(...),
            TransferStatus::Completed, TransferStatus::Rejected, TransferStatus::Compensated => null,
        };
    }

    private function debitSource(Transfer $transfer): void
    {
        $terms = $transfer->terms();

        try {
            $this->onAccount($terms->source, function (Account $source) use ($transfer, $terms): void {
                $source->debitForTransfer($transfer->id(), $terms->amount, $terms->destination);
            });
        } catch (BusinessRuleViolation $refused) {
            $transfer->reject($refused->getMessage());

            return;
        }

        $transfer->markSourceDebited();
    }

    private function creditDestination(Transfer $transfer): void
    {
        $terms = $transfer->terms();

        try {
            $this->onAccount($terms->destination, function (Account $destination) use ($transfer, $terms): void {
                $destination->creditFromTransfer($transfer->id(), $terms->amount, $terms->source);
            });
        } catch (BusinessRuleViolation $refused) {
            $transfer->markCreditFailed($refused->getMessage());

            return;
        }

        $transfer->complete();
    }

    private function compensate(Transfer $transfer): void
    {
        $terms = $transfer->terms();

        $this->onAccount($terms->source, function (Account $source) use ($transfer, $terms): void {
            $source->reverseTransferDebit($transfer->id(), $terms->amount, $terms->destination);
        });

        $transfer->markCompensated();
    }

    /**
     * Load, change and save one account, retrying on concurrent writes.
     *
     * @param  callable(Account): void  $change
     */
    private function onAccount(AccountId $id, callable $change): void
    {
        $this->retry->run(function () use ($id, $change): void {
            $account = $this->accounts->get($id);
            $change($account);
            $this->accounts->save($account);
        });
    }
}
