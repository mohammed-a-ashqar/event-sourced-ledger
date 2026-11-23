<?php

declare(strict_types=1);

namespace Ledger\Application\Account;

use Ledger\Domain\Account\Account;
use Ledger\Domain\Account\AccountRepository;
use Ledger\Domain\Account\Exception\AccountAlreadyExists;
use Ledger\Domain\Shared\Currency;
use Ledger\Domain\Shared\Money;

final readonly class OpenAccountHandler
{
    public function __construct(private AccountRepository $accounts) {}

    public function __invoke(OpenAccount $command): void
    {
        if ($this->accounts->exists($command->accountId)) {
            throw AccountAlreadyExists::withId($command->accountId);
        }

        $currency = Currency::of($command->currency);

        $this->accounts->save(Account::open(
            $command->accountId,
            $command->ownerName,
            $currency,
            Money::of($command->overdraftLimit, $currency),
        ));
    }
}
