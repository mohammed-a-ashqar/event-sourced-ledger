<?php

declare(strict_types=1);

namespace Ledger\Application\Account;

use Ledger\Domain\Account\AccountId;

final readonly class OpenAccount
{
    public function __construct(
        public AccountId $accountId,
        public string $ownerName,
        public string $currency,
        public int $overdraftLimit,
    ) {}
}
