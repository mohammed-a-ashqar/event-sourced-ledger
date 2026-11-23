<?php

declare(strict_types=1);

namespace Ledger\Infrastructure\ReadModel;

use Ledger\Domain\Shared\Money;

/**
 * The JSON shape of an amount: exact minor units plus a display string.
 */
final class MoneyView
{
    /**
     * @return array{amount: int, currency: string, formatted: string}
     */
    public static function of(int $minorUnits, string $currency): array
    {
        return [
            'amount' => $minorUnits,
            'currency' => $currency,
            'formatted' => Money::of($minorUnits, $currency)->toDecimal(),
        ];
    }
}
