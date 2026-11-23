<?php

declare(strict_types=1);

namespace Ledger\Application\Query;

use DateTimeImmutable;
use Ledger\Domain\Account\AccountId;
use Ledger\Domain\Account\AccountRepository;
use Ledger\Domain\Account\Exception\AccountNotFound;
use Ledger\Domain\Shared\Money;

/**
 * Temporal query: what was the balance at a past moment?
 *
 * Answered by replaying the account stream up to that moment, so it works
 * for any point in time without storing historical balances anywhere.
 */
final readonly class BalanceAsOf
{
    public function __construct(private AccountRepository $accounts) {}

    /**
     * @return Money|null null when the account was opened after $pointInTime
     *
     * @throws AccountNotFound when the account never existed
     */
    public function __invoke(AccountId $accountId, DateTimeImmutable $pointInTime): ?Money
    {
        if (! $this->accounts->exists($accountId)) {
            throw AccountNotFound::withId($accountId);
        }

        return $this->accounts->getAsOf($accountId, $pointInTime)?->balance();
    }
}
