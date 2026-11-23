<?php

declare(strict_types=1);

namespace Ledger\Domain\Ledger;

enum Direction: string
{
    case Debit = 'debit';
    case Credit = 'credit';
}
