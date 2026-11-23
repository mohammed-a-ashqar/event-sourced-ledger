<?php

declare(strict_types=1);

namespace Tests\Unit\EventSourcing;

use DateTimeImmutable;
use Ledger\Domain\Account\Account;
use Ledger\Domain\Shared\Currency;
use Ledger\Domain\Shared\TransactionId;
use Ledger\EventSourcing\AggregateRepository;
use Ledger\EventSourcing\Clock\FrozenClock;
use Ledger\EventSourcing\CorrelationContext;
use Ledger\EventSourcing\EventEnvelope;
use Ledger\EventSourcing\InMemoryEventStore;
use Ledger\EventSourcing\Snapshot\EveryNEvents;
use Ledger\EventSourcing\Snapshot\InMemorySnapshotStore;
use Ledger\EventSourcing\Snapshot\Snapshot;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Support\Fixtures as F;

final class AggregateRepositoryTest extends TestCase
{
    private InMemoryEventStore $store;

    private InMemorySnapshotStore $snapshots;

    private FrozenClock $clock;

    private CorrelationContext $correlation;

    /** @var AggregateRepository<Account> */
    private AggregateRepository $repository;

    protected function setUp(): void
    {
        $this->store = new InMemoryEventStore;
        $this->snapshots = new InMemorySnapshotStore;
        $this->clock = new FrozenClock('2026-03-01 09:00:00');
        $this->correlation = new CorrelationContext;
        $this->repository = new AggregateRepository(
            $this->store,
            Account::class,
            'account',
            $this->clock,
            $this->correlation,
            $this->snapshots,
            new EveryNEvents(3),
        );
    }

    private function openAlice(): Account
    {
        return Account::open(F::alice(), 'Alice', Currency::of('EUR'), F::eur(0));
    }

    #[Test]
    public function an_unknown_aggregate_loads_as_null(): void
    {
        self::assertNull($this->repository->load(F::ALICE));
    }

    #[Test]
    public function saving_appends_pending_events_with_consecutive_versions(): void
    {
        $account = $this->openAlice();
        $account->deposit(TransactionId::generate(), F::eur(100));

        $stored = $this->repository->save($account);

        self::assertSame([1, 2], array_map(fn (EventEnvelope $e) => $e->version, $stored));
        self::assertSame('account-'.F::ALICE, $stored[0]->streamId);
        self::assertSame([], $account->pendingEvents());
        self::assertSame(2, $account->persistedVersion());
        self::assertSame([], $this->repository->save($account), 'Saving without changes is a no-op.');
    }

    #[Test]
    public function metadata_links_each_write_to_its_cause(): void
    {
        $this->correlation->within('request-1', 'command-1', function (): void {
            $account = $this->openAlice();
            $first = $this->repository->save($account);

            $account->deposit(TransactionId::generate(), F::eur(100));
            $second = $this->repository->save($account);

            self::assertSame('request-1', $first[0]->metadata->correlationId);
            self::assertSame('command-1', $first[0]->metadata->causationId);
            self::assertSame('request-1', $second[0]->metadata->correlationId);
            self::assertSame($first[0]->eventId(), $second[0]->metadata->causationId);
            self::assertEquals($this->clock->now(), $second[0]->metadata->occurredAt);
        });

        self::assertNull($this->correlation->causationId(), 'The scope is restored after the command.');
    }

    #[Test]
    public function a_snapshot_is_taken_when_a_save_crosses_the_interval(): void
    {
        $account = $this->openAlice();
        $account->deposit(TransactionId::generate(), F::eur(100));
        $this->repository->save($account);

        self::assertNull($this->snapshots->latest('account-'.F::ALICE));

        $account->deposit(TransactionId::generate(), F::eur(50));
        $account->deposit(TransactionId::generate(), F::eur(25));
        $this->repository->save($account);

        $snapshot = $this->snapshots->latest('account-'.F::ALICE);
        self::assertNotNull($snapshot);
        self::assertSame(4, $snapshot->version);
        self::assertSame(175, $snapshot->state['balance']);
    }

    #[Test]
    public function loading_starts_from_the_snapshot_and_replays_only_the_tail(): void
    {
        $account = $this->openAlice();
        $account->deposit(TransactionId::generate(), F::eur(100));
        $account->deposit(TransactionId::generate(), F::eur(100));
        $this->repository->save($account);
        $account->deposit(TransactionId::generate(), F::eur(7));
        $this->repository->save($account);

        // Tamper with the cached state: if load() really starts from the
        // snapshot, the tampered balance shows up (plus the 7 from the tail).
        $snapshot = $this->snapshots->latest('account-'.F::ALICE);
        self::assertNotNull($snapshot);
        $this->snapshots->save(new Snapshot($snapshot->streamId, 3, 1, ['balance' => 1_000] + $snapshot->state));

        $loaded = $this->repository->load(F::ALICE);

        self::assertNotNull($loaded);
        self::assertSame(1_007, $loaded->balance()->amount);
        self::assertSame(4, $loaded->version());
    }

    #[Test]
    public function snapshot_plus_tail_equals_a_full_replay(): void
    {
        $account = $this->openAlice();
        $this->repository->save($account);

        for ($i = 1; $i <= 10; $i++) {
            $account->deposit(TransactionId::generate(), F::eur($i));
            $this->repository->save($account);
        }

        $fromSnapshot = $this->repository->load(F::ALICE);
        $fullReplay = Account::reconstituteFromHistory(array_map(
            fn (EventEnvelope $e) => $e->event,
            $this->store->load('account-'.F::ALICE),
        ));

        self::assertNotNull($fromSnapshot);
        self::assertSame(9, $this->snapshots->latest('account-'.F::ALICE)?->version);
        self::assertEquals($fullReplay->snapshotState(), $fromSnapshot->snapshotState());
        self::assertSame($fullReplay->version(), $fromSnapshot->version());
    }

    #[Test]
    public function a_snapshot_with_an_outdated_schema_is_ignored(): void
    {
        $account = $this->openAlice();
        $account->deposit(TransactionId::generate(), F::eur(100));
        $this->repository->save($account);

        $this->snapshots->save(new Snapshot('account-'.F::ALICE, 2, 0, ['legacy' => true]));

        self::assertSame(100, $this->repository->load(F::ALICE)?->balance()->amount);
    }

    #[Test]
    public function it_rebuilds_the_aggregate_as_of_a_past_moment(): void
    {
        $account = $this->openAlice();
        $this->repository->save($account);

        $this->clock->setTo('2026-03-02 10:00:00');
        $account->deposit(TransactionId::generate(), F::eur(100));
        $this->repository->save($account);

        $this->clock->setTo('2026-03-03 10:00:00');
        $account->deposit(TransactionId::generate(), F::eur(50));
        $this->repository->save($account);

        $at = static fn (string $moment) => new DateTimeImmutable($moment.' UTC');

        self::assertNull($this->repository->loadAsOf(F::ALICE, $at('2026-03-01 08:59:59')));
        self::assertSame(0, $this->repository->loadAsOf(F::ALICE, $at('2026-03-02 09:59:59'))?->balance()->amount);
        self::assertSame(100, $this->repository->loadAsOf(F::ALICE, $at('2026-03-02 10:00:00'))?->balance()->amount);
        self::assertSame(150, $this->repository->loadAsOf(F::ALICE, $at('2026-12-31 00:00:00'))?->balance()->amount);
    }

    #[Test]
    public function the_snapshot_interval_is_crossed_even_when_a_save_jumps_over_it(): void
    {
        $policy = new EveryNEvents(10);

        self::assertFalse($policy->shouldSnapshot(0, 9));
        self::assertTrue($policy->shouldSnapshot(9, 10));
        self::assertTrue($policy->shouldSnapshot(8, 12));
        self::assertFalse($policy->shouldSnapshot(10, 19));
        self::assertTrue($policy->shouldSnapshot(19, 31));
    }
}
