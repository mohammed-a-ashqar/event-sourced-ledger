<?php

declare(strict_types=1);

namespace Tests\Integration;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Ledger\Domain\Account\Account;
use Ledger\Domain\Account\Event\AccountOpened;
use Ledger\Domain\Account\Event\MoneyDeposited;
use Ledger\Domain\Shared\Money;
use Ledger\Domain\Shared\TransactionId;
use Ledger\EventSourcing\EventEnvelope;
use Ledger\EventSourcing\EventStore;
use Ledger\EventSourcing\Serialization\EventSerializer;
use Ledger\Infrastructure\EventStore\DatabaseEventStore;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\EventStoreContract;
use Tests\Support\Fixtures as F;
use Tests\TestCase;

final class DatabaseEventStoreTest extends TestCase
{
    use EventStoreContract;

    protected function createEventStore(array $listeners = []): EventStore
    {
        return new DatabaseEventStore(
            $this->app->make(ConnectionInterface::class),
            $this->app->make(EventSerializer::class),
            $listeners,
        );
    }

    #[Test]
    public function events_are_stored_by_type_name_and_schema_version_as_json(): void
    {
        $this->createEventStore()->append('account-'.F::ALICE, 0, [self::envelope('account-'.F::ALICE, 1, F::opened())]);

        $row = DB::table('stored_events')->first();

        self::assertNotNull($row);
        self::assertSame('account.opened', $row->event_type);
        self::assertSame(2, self::int($row->schema_version));
        self::assertSame(
            ['account_id' => F::ALICE, 'owner_name' => 'Alice', 'currency' => 'EUR', 'overdraft_limit' => 0],
            json_decode(self::str($row->payload), true),
        );
        self::assertSame(
            ['event_id', 'correlation_id', 'causation_id', 'occurred_at'],
            array_keys((array) json_decode(self::str($row->metadata), true)),
        );
    }

    #[Test]
    public function the_unique_index_is_the_last_line_of_defence_against_lost_updates(): void
    {
        $this->createEventStore()->append('s', 0, [self::envelope('s', 1)]);

        // Bypass the store entirely, as a second process would.
        $this->expectException(UniqueConstraintViolationException::class);

        DB::table('stored_events')->insert([
            'stream_id' => 's',
            'version' => 1,
            'event_type' => 'account.money_deposited',
            'schema_version' => 2,
            'payload' => '{}',
            'metadata' => '{}',
            'occurred_at' => '2026-03-01 00:00:00.000000',
        ]);
    }

    #[Test]
    public function legacy_rows_written_with_an_old_schema_are_upcast_when_read(): void
    {
        $stream = 'account-'.F::ALICE;
        $metadata = static fn (int $n) => json_encode([
            'event_id' => sprintf('aaaaaaaa-aaaa-4aaa-8aaa-%012d', $n),
            'correlation_id' => 'legacy-import',
            'causation_id' => null,
            'occurred_at' => '2024-05-01T10:00:00.000000+00:00',
        ]);

        // Exactly what the 2024 version of the service wrote.
        DB::table('stored_events')->insert([
            [
                'stream_id' => $stream, 'version' => 1, 'event_type' => 'account.opened', 'schema_version' => 1,
                'payload' => json_encode(['account_id' => F::ALICE, 'owner' => 'Alice', 'currency' => 'EUR']),
                'metadata' => $metadata(1), 'occurred_at' => '2024-05-01 10:00:00.000000',
            ],
            [
                'stream_id' => $stream, 'version' => 2, 'event_type' => 'account.money_deposited', 'schema_version' => 1,
                'payload' => json_encode(['account_id' => F::ALICE, 'transaction_id' => F::TRANSACTION, 'amount' => '12.50', 'currency' => 'EUR']),
                'metadata' => $metadata(2), 'occurred_at' => '2024-05-01 10:00:00.000000',
            ],
        ]);

        $events = array_map(fn (EventEnvelope $e) => $e->event, $this->createEventStore()->load($stream));

        self::assertEquals(F::opened(), $events[0]);
        self::assertInstanceOf(AccountOpened::class, $events[0]);
        self::assertEquals(
            new MoneyDeposited(F::alice(), TransactionId::fromString(F::TRANSACTION), Money::of(1_250, 'EUR'), null),
            $events[1],
        );
        self::assertSame(1_250, Account::reconstituteFromHistory($events)->balance()->amount);
    }

    #[Test]
    public function large_amounts_survive_json_storage_exactly(): void
    {
        $huge = new MoneyDeposited(F::alice(), F::transactionId(), Money::of(PHP_INT_MAX, 'EUR'), null);
        $store = $this->createEventStore();

        $store->append('s', 0, [self::envelope('s', 1, $huge)]);

        self::assertEquals($huge, $store->load('s')[0]->event);
    }
}
