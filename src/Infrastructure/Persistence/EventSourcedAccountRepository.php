<?php

declare(strict_types=1);

namespace Ledger\Infrastructure\Persistence;

use DateTimeImmutable;
use Ledger\Domain\Account\Account;
use Ledger\Domain\Account\AccountId;
use Ledger\Domain\Account\AccountRepository;
use Ledger\Domain\Account\Exception\AccountNotFound;
use Ledger\EventSourcing\AggregateRepository;

final readonly class EventSourcedAccountRepository implements AccountRepository
{
    public const STREAM_PREFIX = 'account';

    /**
     * @param  AggregateRepository<Account>  $repository
     */
    public function __construct(private AggregateRepository $repository) {}

    public function get(AccountId $id): Account
    {
        return $this->repository->load($id->value) ?? throw AccountNotFound::withId($id);
    }

    public function exists(AccountId $id): bool
    {
        return $this->repository->load($id->value) !== null;
    }

    public function save(Account $account): void
    {
        $this->repository->save($account);
    }

    public function getAsOf(AccountId $id, DateTimeImmutable $pointInTime): ?Account
    {
        return $this->repository->loadAsOf($id->value, $pointInTime);
    }
}
