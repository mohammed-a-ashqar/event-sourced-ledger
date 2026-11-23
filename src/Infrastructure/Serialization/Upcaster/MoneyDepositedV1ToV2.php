<?php

declare(strict_types=1);

namespace Ledger\Infrastructure\Serialization\Upcaster;

use Ledger\Domain\Shared\Currency;
use Ledger\Domain\Shared\Money;
use Ledger\EventSourcing\Payload;
use Ledger\EventSourcing\Upcasting\Upcaster;

/**
 * v1 stored the amount as a decimal string ("12.50"); v2 stores integer
 * minor units (1250) and adds an optional reference.
 *
 * The conversion uses the currency's ISO exponent, so "1.250" KWD becomes
 * 1250 fils and "500" JPY stays 500 yen.
 */
final class MoneyDepositedV1ToV2 implements Upcaster
{
    public function eventType(): string
    {
        return 'account.money_deposited';
    }

    public function fromVersion(): int
    {
        return 1;
    }

    public function upcast(array $payload): array
    {
        $currency = Currency::of(Payload::string($payload, 'currency'));

        return [
            'account_id' => Payload::string($payload, 'account_id'),
            'transaction_id' => Payload::string($payload, 'transaction_id'),
            'amount' => Money::fromDecimal(Payload::string($payload, 'amount'), $currency)->amount,
            'currency' => $currency->code,
            'reference' => null,
        ];
    }
}
