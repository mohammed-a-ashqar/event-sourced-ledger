<?php

declare(strict_types=1);

namespace Ledger\Domain\Account;

enum AccountStatus: string
{
    case Active = 'active';
    case Frozen = 'frozen';
}
