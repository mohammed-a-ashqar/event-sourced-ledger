<?php

declare(strict_types=1);

namespace Tests\Support;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Ledger\EventSourcing\AppendListener;
use Ledger\EventSourcing\ConcurrencyException;
use Ledger\EventSourcing\DomainEvent;
use Ledger\EventSourcing\EventEnvelope;
use Ledger\EventSourcing\EventStore;
use Ledger\EventSourcing\Metadata;
use Ledger\EventSourcing\Uuid;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Support\Fixtures as F;

/**
 * Behaviour every EventStore implementation must share.
 *
 * Used by both the in-memory and the database store tests, so the fast
 * fake can never drift from the real thing.
 */
trait EventStoreContract
{
    /**
     * @param  list<AppendListener>  $listeners
     */
    abstract protected function createEventStore(array $listeners = []): EventStore;

    protected static function envelope(string $stream, int $version, ?DomainEvent $event = null, string $at = '2026-03-01 12:00:00.000000'): EventEnvelope
    {
        return new EventEnvelope(
            $stream,
            $version,
            $event ?? F::deposited(100 * $version),
            new Metadata(Uuid::v4(), 'corr-1', 'cause-'.$version, new DateTimeImmutable($at, new DateTimeZone('UTC'))),
        );
    }

    #[Test]
    public function it_appends_and_loads_a_stream_in_version_order(): void
    {
        $store = $this->createEventStore();
        $first = self::envelope('account-a', 1, F::opened());
        $second = self::envelope('account-a', 2, F::deposited(250));

        $store->append('account-a', 0, [$first, $second]);
        $store->append('account-a', 2, [self::envelope('account-a', 3)]);

        $loaded = $store->load('account-a');

        self::assertSame([1, 2, 3], array_map(fn (EventEnvelope $e) => $e->version, $loaded));
        self::assertEquals($first->event, $loaded[0]->event);
        self::assertEquals($second->event, $loaded[1]->event);
    }

    #[Test]
    public function it_loads_only_the_tail_after_a_version(): void
    {
        $store = $this->createEventStore();
        $store->append('s', 0, [self::envelope('s', 1), self::envelope('s', 2), self::envelope('s', 3)]);

        self::assertSame([3], array_map(fn (EventEnvelope $e) => $e->version, $store->load('s', 2)));
        self::assertSame([], $store->load('s', 3));
        self::assertSame([], $store->load('unknown'));
    }

    #[Test]
    public function metadata_survives_the_round_trip(): void
    {
        $store = $this->createEventStore();
        $original = self::envelope('s', 1, null, '2026-03-01 12:34:56.789012');

        $store->append('s', 0, [$original]);
        $loaded = $store->load('s')[0];

        self::assertSame($original->metadata->eventId, $loaded->metadata->eventId);
        self::assertSame('corr-1', $loaded->metadata->correlationId);
        self::assertSame('cause-1', $loaded->metadata->causationId);
        self::assertSame('2026-03-01T12:34:56.789012+00:00', $loaded->metadata->occurredAt->format(Metadata::DATE_FORMAT));
    }

    #[Test]
    public function positions_are_global_and_increasing_across_streams(): void
    {
        $store = $this->createEventStore();
        $store->append('a', 0, [self::envelope('a', 1), self::envelope('a', 2)]);
        $store->append('b', 0, [self::envelope('b', 1)]);
        $store->append('a', 2, [self::envelope('a', 3)]);

        $all = $store->loadFromPosition(0, 100);
        $positions = array_map(fn (EventEnvelope $e) => (int) $e->position, $all);

        self::assertSame(['a', 'a', 'b', 'a'], array_map(fn (EventEnvelope $e) => $e->streamId, $all));
        foreach (array_slice($positions, 1) as $i => $position) {
            self::assertGreaterThan($positions[$i], $position);
        }

        $page = $store->loadFromPosition($positions[1], 2);
        self::assertSame([$positions[2], $positions[3]], array_map(fn (EventEnvelope $e) => $e->position, $page));
    }

    #[Test]
    public function a_stale_expected_version_is_a_concurrency_conflict_and_writes_nothing(): void
    {
        $store = $this->createEventStore();
        $store->append('s', 0, [self::envelope('s', 1)]);

        try {
            $store->append('s', 0, [self::envelope('s', 1), self::envelope('s', 2)]);
            self::fail('Expected a concurrency conflict.');
        } catch (ConcurrencyException) {
            self::assertCount(1, $store->load('s'));
        }
    }

    #[Test]
    public function two_writers_that_loaded_the_same_version_cannot_both_append(): void
    {
        $store = $this->createEventStore();
        $store->append('s', 0, [self::envelope('s', 1)]);

        // Both writers loaded version 1 and decided independently.
        $store->append('s', 1, [self::envelope('s', 2, F::deposited(1))]);

        $this->expectException(ConcurrencyException::class);
        $store->append('s', 1, [self::envelope('s', 2, F::deposited(2))]);
    }

    #[Test]
    public function an_expected_version_beyond_the_stream_is_rejected(): void
    {
        $store = $this->createEventStore();
        $store->append('s', 0, [self::envelope('s', 1)]);

        $this->expectException(ConcurrencyException::class);
        $store->append('s', 5, [self::envelope('s', 6)]);
    }

    #[Test]
    public function versions_must_follow_the_expected_version(): void
    {
        $store = $this->createEventStore();

        $this->expectException(InvalidArgumentException::class);
        $store->append('s', 0, [self::envelope('s', 2)]);
    }

    #[Test]
    public function it_loads_a_stream_up_to_a_point_in_time(): void
    {
        $store = $this->createEventStore();
        $store->append('s', 0, [
            self::envelope('s', 1, null, '2026-03-01 09:00:00.000000'),
            self::envelope('s', 2, null, '2026-03-01 10:00:00.000000'),
            self::envelope('s', 3, null, '2026-03-01 10:00:00.000001'),
        ]);

        $until = new DateTimeImmutable('2026-03-01 10:00:00', new DateTimeZone('UTC'));

        self::assertSame([1, 2], array_map(fn (EventEnvelope $e) => $e->version, $store->loadUntil('s', $until)));
    }

    #[Test]
    public function listeners_see_the_appended_events_with_positions(): void
    {
        $listener = new class implements AppendListener
        {
            /** @var list<EventEnvelope> */
            public array $received = [];

            public function onAppend(array $events): void
            {
                array_push($this->received, ...$events);
            }
        };

        $store = $this->createEventStore([$listener]);
        $store->append('s', 0, [self::envelope('s', 1), self::envelope('s', 2)]);

        self::assertCount(2, $listener->received);
        self::assertNotNull($listener->received[0]->position);
        self::assertSame(
            array_map(fn (EventEnvelope $e) => $e->position, $store->load('s')),
            array_map(fn (EventEnvelope $e) => $e->position, $listener->received),
        );
    }

    #[Test]
    public function a_failing_listener_rolls_the_append_back(): void
    {
        $listener = new class implements AppendListener
        {
            public function onAppend(array $events): void
            {
                throw new RuntimeException('outbox unavailable');
            }
        };

        $store = $this->createEventStore([$listener]);

        try {
            $store->append('s', 0, [self::envelope('s', 1)]);
            self::fail('Expected the listener failure to propagate.');
        } catch (RuntimeException $e) {
            self::assertSame('outbox unavailable', $e->getMessage());
        }

        self::assertSame([], $store->load('s'));
    }
}
