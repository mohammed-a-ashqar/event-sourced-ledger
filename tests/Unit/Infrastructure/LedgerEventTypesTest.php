<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure;

use Ledger\Domain\Account\Event\AccountFrozen;
use Ledger\Domain\Account\Event\AccountOpened;
use Ledger\Domain\Account\Event\AccountUnfrozen;
use Ledger\Domain\Account\Event\MoneyDeposited;
use Ledger\Domain\Account\Event\MoneyWithdrawn;
use Ledger\Domain\Account\Event\TransferCredited;
use Ledger\Domain\Account\Event\TransferDebited;
use Ledger\Domain\Account\Event\TransferDebitReversed;
use Ledger\Domain\Shared\Currency;
use Ledger\Domain\Shared\Money;
use Ledger\Domain\Shared\TransactionId;
use Ledger\Domain\Transfer\Event\TransferCompensated;
use Ledger\Domain\Transfer\Event\TransferCompleted;
use Ledger\Domain\Transfer\Event\TransferCreditFailed;
use Ledger\Domain\Transfer\Event\TransferInitiated;
use Ledger\Domain\Transfer\Event\TransferRejected;
use Ledger\Domain\Transfer\Event\TransferSourceDebited;
use Ledger\Domain\Transfer\TransferTerms;
use Ledger\EventSourcing\DomainEvent;
use Ledger\EventSourcing\Serialization\SerializedEvent;
use Ledger\Infrastructure\Serialization\LedgerEventTypes;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Support\Fixtures as F;

final class LedgerEventTypesTest extends TestCase
{
    /**
     * @return iterable<string, array{DomainEvent}>
     */
    public static function everyEvent(): iterable
    {
        $terms = new TransferTerms(F::alice(), F::bob(), F::eur(400));

        yield 'opened' => [new AccountOpened(F::alice(), 'Alice', Currency::of('KWD'), Money::of(1_500, 'KWD'))];
        yield 'deposited' => [new MoneyDeposited(F::alice(), F::transactionId(), F::eur(10), 'ref')];
        yield 'withdrawn' => [new MoneyWithdrawn(F::alice(), F::transactionId(), F::eur(10), null)];
        yield 'transfer debited' => [new TransferDebited(F::alice(), F::transferId(), F::eur(400), F::bob())];
        yield 'transfer credited' => [new TransferCredited(F::bob(), F::transferId(), F::eur(400), F::alice())];
        yield 'debit reversed' => [new TransferDebitReversed(F::alice(), F::transferId(), F::eur(400), F::bob())];
        yield 'frozen' => [new AccountFrozen(F::alice(), 'KYC')];
        yield 'unfrozen' => [new AccountUnfrozen(F::alice())];
        yield 'transfer initiated' => [new TransferInitiated(F::transferId(), $terms)];
        yield 'source debited' => [new TransferSourceDebited(F::transferId())];
        yield 'transfer completed' => [new TransferCompleted(F::transferId(), $terms)];
        yield 'transfer rejected' => [new TransferRejected(F::transferId(), $terms, 'insufficient funds')];
        yield 'credit failed' => [new TransferCreditFailed(F::transferId(), 'frozen')];
        yield 'transfer compensated' => [new TransferCompensated(F::transferId(), $terms, 'frozen')];
    }

    #[Test]
    #[DataProvider('everyEvent')]
    public function every_registered_event_survives_a_json_round_trip(DomainEvent $event): void
    {
        $serializer = LedgerEventTypes::serializer();
        $serialized = $serializer->serialize($event);
        $json = json_decode(json_encode($serialized->payload, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

        self::assertIsArray($json);
        self::assertEquals($event, $serializer->deserialize(new SerializedEvent($serialized->eventType, $serialized->schemaVersion, $json)));
    }

    #[Test]
    public function the_registry_covers_every_event_exercised_above(): void
    {
        $covered = array_map(fn (array $case) => $case[0]::class, iterator_to_array(self::everyEvent(), false));

        self::assertEqualsCanonicalizing(LedgerEventTypes::all(), $covered);
    }

    #[Test]
    public function account_opened_v1_is_upcast_with_a_renamed_owner_and_no_overdraft(): void
    {
        $event = LedgerEventTypes::serializer()->deserialize(new SerializedEvent('account.opened', 1, [
            'account_id' => F::ALICE,
            'owner' => 'Alice',
            'currency' => 'EUR',
        ]));

        self::assertEquals(F::opened(), $event);
    }

    /**
     * @return iterable<string, array{string, string, int}>
     */
    public static function legacyDeposits(): iterable
    {
        yield 'euros' => ['12.50', 'EUR', 1_250];
        yield 'whole euros' => ['7', 'EUR', 700];
        yield 'yen have no minor unit' => ['500', 'JPY', 500];
        yield 'dinars have three' => ['1.25', 'KWD', 1_250];
    }

    #[Test]
    #[DataProvider('legacyDeposits')]
    public function money_deposited_v1_decimal_amounts_become_minor_units(string $decimal, string $currency, int $minor): void
    {
        $event = LedgerEventTypes::serializer()->deserialize(new SerializedEvent('account.money_deposited', 1, [
            'account_id' => F::ALICE,
            'transaction_id' => F::TRANSACTION,
            'amount' => $decimal,
            'currency' => $currency,
        ]));

        self::assertEquals(
            new MoneyDeposited(F::alice(), TransactionId::fromString(F::TRANSACTION), Money::of($minor, $currency), null),
            $event,
        );
    }
}
