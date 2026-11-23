<?php

declare(strict_types=1);

namespace Ledger\Infrastructure\Persistence;

use Ledger\Domain\Transfer\Transfer;
use Ledger\Domain\Transfer\TransferId;
use Ledger\Domain\Transfer\TransferRepository;
use Ledger\EventSourcing\AggregateRepository;

final readonly class EventSourcedTransferRepository implements TransferRepository
{
    public const STREAM_PREFIX = 'transfer';

    /**
     * @param  AggregateRepository<Transfer>  $repository
     */
    public function __construct(private AggregateRepository $repository) {}

    public function find(TransferId $id): ?Transfer
    {
        return $this->repository->load($id->value);
    }

    public function save(Transfer $transfer): void
    {
        $this->repository->save($transfer);
    }
}
