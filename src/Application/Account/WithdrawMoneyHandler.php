<?php

declare(strict_types=1);

namespace Ledger\Application\Account;

use Ledger\Domain\Account\AccountRepository;
use Ledger\Domain\Shared\Money;

final readonly class WithdrawMoneyHandler
{
    public function __construct(private AccountRepository $accounts) {}

    public function __invoke(WithdrawMoney $command): void
    {
        $account = $this->accounts->get($command->accountId);
        $account->withdraw($command->transactionId, Money::of($command->amount, $command->currency), $command->reference);

        $this->accounts->save($account);
    }
}
