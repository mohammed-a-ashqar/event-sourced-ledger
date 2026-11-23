<?php

declare(strict_types=1);

namespace Ledger\Infrastructure\Serialization;

use Ledger\Domain\Account\Event\AccountFrozen;
use Ledger\Domain\Account\Event\AccountOpened;
use Ledger\Domain\Account\Event\AccountUnfrozen;
use Ledger\Domain\Account\Event\MoneyDeposited;
use Ledger\Domain\Account\Event\MoneyWithdrawn;
use Ledger\Domain\Account\Event\TransferCredited;
use Ledger\Domain\Account\Event\TransferDebited;
use Ledger\Domain\Account\Event\TransferDebitReversed;
use Ledger\Domain\Transfer\Event\TransferCompensated;
use Ledger\Domain\Transfer\Event\TransferCompleted;
use Ledger\Domain\Transfer\Event\TransferCreditFailed;
use Ledger\Domain\Transfer\Event\TransferInitiated;
use Ledger\Domain\Transfer\Event\TransferRejected;
use Ledger\Domain\Transfer\Event\TransferSourceDebited;
use Ledger\EventSourcing\DomainEvent;
use Ledger\EventSourcing\Serialization\EventSerializer;
use Ledger\EventSourcing\Serialization\EventTypeRegistry;
use Ledger\EventSourcing\Upcasting\UpcasterChain;
use Ledger\Infrastructure\Serialization\Upcaster\AccountOpenedV1ToV2;
use Ledger\Infrastructure\Serialization\Upcaster\MoneyDepositedV1ToV2;

/**
 * The single list of persisted event types and the upcasters for their old schemas.
 */
final class LedgerEventTypes
{
    /**
     * @return list<class-string<DomainEvent>>
     */
    public static function all(): array
    {
        return [
            AccountOpened::class,
            MoneyDeposited::class,
            MoneyWithdrawn::class,
            TransferDebited::class,
            TransferCredited::class,
            TransferDebitReversed::class,
            AccountFrozen::class,
            AccountUnfrozen::class,
            TransferInitiated::class,
            TransferSourceDebited::class,
            TransferCompleted::class,
            TransferRejected::class,
            TransferCreditFailed::class,
            TransferCompensated::class,
        ];
    }

    public static function upcasters(): UpcasterChain
    {
        return new UpcasterChain([
            new AccountOpenedV1ToV2,
            new MoneyDepositedV1ToV2,
        ]);
    }

    public static function serializer(): EventSerializer
    {
        return new EventSerializer(new EventTypeRegistry(self::all()), self::upcasters());
    }
}
