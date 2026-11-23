<?php

declare(strict_types=1);

namespace Ledger\Domain\Transfer;

interface TransferRepository
{
    public function find(TransferId $id): ?Transfer;

    public function save(Transfer $transfer): void;
}
