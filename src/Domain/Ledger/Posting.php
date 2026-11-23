<?php

declare(strict_types=1);

namespace Ledger\Domain\Ledger;

use Ledger\Domain\Shared\InvalidAmount;
use Ledger\Domain\Shared\Money;

final readonly class Posting
{
    private function __construct(
        public LedgerAccount $account,
        public Direction $direction,
        public Money $amount,
    ) {
        if (! $amount->isPositive()) {
            throw InvalidAmount::mustBePositive($amount);
        }
    }

    public static function debit(LedgerAccount $account, Money $amount): self
    {
        return new self($account, Direction::Debit, $amount);
    }

    public static function credit(LedgerAccount $account, Money $amount): self
    {
        return new self($account, Direction::Credit, $amount);
    }
}
