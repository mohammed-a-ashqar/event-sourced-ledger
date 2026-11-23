<?php

declare(strict_types=1);

namespace Ledger\Application\Account;

use Ledger\Domain\Account\AccountRepository;

final readonly class UnfreezeAccountHandler
{
    public function __construct(private AccountRepository $accounts) {}

    public function __invoke(UnfreezeAccount $command): void
    {
        $account = $this->accounts->get($command->accountId);
        $account->unfreeze();

        $this->accounts->save($account);
    }
}
