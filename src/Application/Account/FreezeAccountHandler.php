<?php

declare(strict_types=1);

namespace Ledger\Application\Account;

use Ledger\Domain\Account\AccountRepository;

final readonly class FreezeAccountHandler
{
    public function __construct(private AccountRepository $accounts) {}

    public function __invoke(FreezeAccount $command): void
    {
        $account = $this->accounts->get($command->accountId);
        $account->freeze($command->reason);

        $this->accounts->save($account);
    }
}
