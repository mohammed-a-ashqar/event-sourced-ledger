<?php

declare(strict_types=1);

namespace Ledger\Application\Transfer;

use Ledger\Domain\Account\AccountId;
use Ledger\Domain\Transfer\TransferId;

final readonly class TransferMoney
{
    public function __construct(
        public TransferId $transferId,
        public AccountId $source,
        public AccountId $destination,
        public int $amount,
        public string $currency,
    ) {}
}
