<?php

declare(strict_types=1);

namespace Ledger\Application\Account;

use Ledger\Domain\Account\AccountId;

final readonly class FreezeAccount
{
    public function __construct(
        public AccountId $accountId,
        public string $reason,
    ) {}
}
