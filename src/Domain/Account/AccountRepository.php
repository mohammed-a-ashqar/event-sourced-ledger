<?php

declare(strict_types=1);

namespace Ledger\Domain\Account;

use DateTimeImmutable;
use Ledger\Domain\Account\Exception\AccountNotFound;

/**
 * Persistence port for the Account aggregate.
 */
interface AccountRepository
{
    /**
     * @throws AccountNotFound
     */
    public function get(AccountId $id): Account;

    public function exists(AccountId $id): bool;

    public function save(Account $account): void;

    /**
     * The account exactly as it was at the given moment, or null if it did not exist yet.
     */
    public function getAsOf(AccountId $id, DateTimeImmutable $pointInTime): ?Account;
}
