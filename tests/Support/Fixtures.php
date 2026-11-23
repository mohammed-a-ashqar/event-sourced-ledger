<?php

declare(strict_types=1);

namespace Tests\Support;

use Ledger\Domain\Account\AccountId;
use Ledger\Domain\Account\Event\AccountOpened;
use Ledger\Domain\Account\Event\MoneyDeposited;
use Ledger\Domain\Shared\Currency;
use Ledger\Domain\Shared\Money;
use Ledger\Domain\Shared\TransactionId;
use Ledger\Domain\Transfer\TransferId;

/**
 * Stable ids and canned events so tests read like specifications.
 */
final class Fixtures
{
    public const ALICE = '11111111-1111-4111-8111-111111111111';

    public const BOB = '22222222-2222-4222-8222-222222222222';

    public const TRANSFER = '33333333-3333-4333-8333-333333333333';

    public const TRANSACTION = '44444444-4444-4444-8444-444444444444';

    public static function alice(): AccountId
    {
        return AccountId::fromString(self::ALICE);
    }

    public static function bob(): AccountId
    {
        return AccountId::fromString(self::BOB);
    }

    public static function transferId(): TransferId
    {
        return TransferId::fromString(self::TRANSFER);
    }

    public static function transactionId(): TransactionId
    {
        return TransactionId::fromString(self::TRANSACTION);
    }

    public static function eur(int $minor): Money
    {
        return Money::of($minor, 'EUR');
    }

    public static function opened(?AccountId $id = null, int $overdraftLimit = 0, string $currency = 'EUR'): AccountOpened
    {
        return new AccountOpened($id ?? self::alice(), 'Alice', Currency::of($currency), Money::of($overdraftLimit, $currency));
    }

    public static function deposited(int $minor, ?AccountId $id = null): MoneyDeposited
    {
        return new MoneyDeposited($id ?? self::alice(), TransactionId::generate(), self::eur($minor), null);
    }
}
