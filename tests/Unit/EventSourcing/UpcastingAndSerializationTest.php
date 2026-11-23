<?php

declare(strict_types=1);

namespace Tests\Unit\EventSourcing;

use InvalidArgumentException;
use Ledger\Domain\Account\Event\AccountOpened;
use Ledger\Domain\Account\Event\MoneyDeposited;
use Ledger\EventSourcing\DomainEvent;
use Ledger\EventSourcing\Serialization\EventSerializer;
use Ledger\EventSourcing\Serialization\EventTypeRegistry;
use Ledger\EventSourcing\Serialization\SerializedEvent;
use Ledger\EventSourcing\Serialization\UnknownEventType;
use Ledger\EventSourcing\Upcasting\MissingUpcaster;
use Ledger\EventSourcing\Upcasting\Upcaster;
use Ledger\EventSourcing\Upcasting\UpcasterChain;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Support\Fixtures as F;

final class UpcastingAndSerializationTest extends TestCase
{
    private static function upcaster(string $type, int $from, callable $change): Upcaster
    {
        return new class($type, $from, $change(...)) implements Upcaster
        {
            public function __construct(private string $type, private int $from, private \Closure $change) {}

            public function eventType(): string
            {
                return $this->type;
            }

            public function fromVersion(): int
            {
                return $this->from;
            }

            public function upcast(array $payload): array
            {
                return ($this->change)($payload);
            }
        };
    }

    #[Test]
    public function the_chain_applies_every_step_in_order(): void
    {
        // Registered out of order on purpose: the chain is keyed by version.
        $chain = new UpcasterChain([
            self::upcaster('thing', 2, fn (array $p) => ['trail' => $p['trail'].'>v3']),
            self::upcaster('thing', 1, fn (array $p) => ['trail' => 'v1>v2']),
        ]);

        self::assertSame(['trail' => 'v1>v2>v3'], $chain->upcast('thing', 1, 3, []));
        self::assertSame(['trail' => 'x>v3'], $chain->upcast('thing', 2, 3, ['trail' => 'x']));
    }

    #[Test]
    public function a_current_payload_passes_through_untouched(): void
    {
        self::assertSame(['a' => 1], (new UpcasterChain)->upcast('thing', 3, 3, ['a' => 1]));
    }

    #[Test]
    public function a_gap_in_the_chain_is_reported(): void
    {
        $chain = new UpcasterChain([self::upcaster('thing', 1, fn (array $p) => $p)]);

        $this->expectException(MissingUpcaster::class);
        $this->expectExceptionMessage('"thing" v2 into v3');

        $chain->upcast('thing', 1, 3, []);
    }

    #[Test]
    public function two_upcasters_for_the_same_step_are_a_configuration_error(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new UpcasterChain([
            self::upcaster('thing', 1, fn (array $p) => $p),
            self::upcaster('thing', 1, fn (array $p) => $p),
        ]);
    }

    #[Test]
    public function events_written_by_newer_code_are_not_silently_misread(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new UpcasterChain)->upcast('thing', 4, 2, []);
    }

    #[Test]
    public function the_serializer_round_trips_events_by_type_name(): void
    {
        $serializer = new EventSerializer(new EventTypeRegistry([MoneyDeposited::class]), new UpcasterChain);
        $event = new MoneyDeposited(F::alice(), F::transactionId(), F::eur(1_250), 'invoice 7');

        $serialized = $serializer->serialize($event);

        self::assertSame('account.money_deposited', $serialized->eventType);
        self::assertSame(2, $serialized->schemaVersion);
        self::assertEquals($event, $serializer->deserialize($serialized));
    }

    #[Test]
    public function the_serializer_upcasts_old_payloads_before_hydrating(): void
    {
        $serializer = new EventSerializer(
            new EventTypeRegistry([AccountOpened::class]),
            new UpcasterChain([self::upcaster('account.opened', 1, fn (array $p) => [
                'account_id' => $p['account_id'],
                'owner_name' => $p['owner'],
                'currency' => $p['currency'],
                'overdraft_limit' => 0,
            ])]),
        );

        $event = $serializer->deserialize(new SerializedEvent('account.opened', 1, [
            'account_id' => F::ALICE,
            'owner' => 'Alice',
            'currency' => 'EUR',
        ]));

        self::assertEquals(F::opened(), $event);
    }

    #[Test]
    public function unknown_event_types_fail_loudly(): void
    {
        $serializer = new EventSerializer(new EventTypeRegistry, new UpcasterChain);

        $this->expectException(UnknownEventType::class);

        $serializer->deserialize(new SerializedEvent('account.closed', 1, []));
    }

    #[Test]
    public function a_type_name_cannot_point_to_two_classes(): void
    {
        $registry = new EventTypeRegistry([MoneyDeposited::class]);
        $impostor = new class implements DomainEvent
        {
            public static function eventType(): string
            {
                return 'account.money_deposited';
            }

            public static function schemaVersion(): int
            {
                return 1;
            }

            public function toPayload(): array
            {
                return [];
            }

            public static function fromPayload(array $payload): self
            {
                return new self;
            }
        };

        $this->expectException(InvalidArgumentException::class);

        $registry->register($impostor::class);
    }
}
