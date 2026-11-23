<?php

declare(strict_types=1);

namespace Ledger\Domain\Ledger;

use Ledger\Domain\Account\AccountId;
use Ledger\Domain\Shared\Currency;

/**
 * A line in the chart of accounts.
 *
 *  - customer:<id>                  a customer wallet (liability of the ledger)
 *  - system:cash:<CUR>              money held for customers (asset)
 *  - system:transfer-clearing:<CUR> transfers in flight between their two legs
 */
final readonly class LedgerAccount
{
    private function __construct(public string $code) {}

    public static function customer(AccountId $id): self
    {
        return new self('customer:'.$id->value);
    }

    public static function cash(Currency $currency): self
    {
        return new self('system:cash:'.$currency->code);
    }

    public static function transferClearing(Currency $currency): self
    {
        return new self('system:transfer-clearing:'.$currency->code);
    }
}
