<?php

declare(strict_types=1);

namespace Ledger\Application\Account;

use Ledger\Domain\Account\AccountId;
use Ledger\Domain\Shared\TransactionId;

final readonly class WithdrawMoney
{
    public function __construct(
        public AccountId $accountId,
        public TransactionId $transactionId,
        public int $amount,
        public string $currency,
        public ?string $reference = null,
    ) {}
}
