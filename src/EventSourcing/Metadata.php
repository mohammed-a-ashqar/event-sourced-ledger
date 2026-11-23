<?php

declare(strict_types=1);

namespace Ledger\EventSourcing;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Envelope data that travels with every stored event.
 *
 * - event_id: unique id of this event (used for idempotent consumers)
 * - correlation_id: shared by everything caused by one external request
 * - causation_id: the command or event that directly caused this event
 */
final readonly class Metadata
{
    public const DATE_FORMAT = 'Y-m-d\TH:i:s.uP';

    public function __construct(
        public string $eventId,
        public string $correlationId,
        public ?string $causationId,
        public DateTimeImmutable $occurredAt,
    ) {}

    /**
     * @return array{event_id: string, correlation_id: string, causation_id: string|null, occurred_at: string}
     */
    public function toArray(): array
    {
        return [
            'event_id' => $this->eventId,
            'correlation_id' => $this->correlationId,
            'causation_id' => $this->causationId,
            'occurred_at' => $this->occurredAt->setTimezone(new DateTimeZone('UTC'))->format(self::DATE_FORMAT),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $occurredAt = DateTimeImmutable::createFromFormat(self::DATE_FORMAT, Payload::string($data, 'occurred_at'));

        if ($occurredAt === false) {
            throw MalformedPayload::expected('occurred_at', 'a '.self::DATE_FORMAT.' date', $data['occurred_at']);
        }

        return new self(
            Payload::string($data, 'event_id'),
            Payload::string($data, 'correlation_id'),
            Payload::nullableString($data, 'causation_id'),
            $occurredAt,
        );
    }
}
