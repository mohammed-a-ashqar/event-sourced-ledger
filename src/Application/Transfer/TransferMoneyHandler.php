<?php

declare(strict_types=1);

namespace Ledger\Application\Transfer;

use Ledger\Domain\Account\AccountRepository;
use Ledger\Domain\Account\Exception\AccountNotFound;
use Ledger\Domain\Shared\Money;
use Ledger\Domain\Transfer\TransferTerms;

final readonly class TransferMoneyHandler
{
    public function __construct(
        private AccountRepository $accounts,
        private TransferProcessManager $processManager,
    ) {}

    public function __invoke(TransferMoney $command): void
    {
        $terms = new TransferTerms(
            $command->source,
            $command->destination,
            Money::of($command->amount, $command->currency),
        );

        foreach ([$terms->source, $terms->destination] as $accountId) {
            if (! $this->accounts->exists($accountId)) {
                throw AccountNotFound::withId($accountId);
            }
        }

        $this->processManager->start($command->transferId, $terms);
    }
}
