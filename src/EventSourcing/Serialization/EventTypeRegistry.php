<?php

declare(strict_types=1);

namespace Ledger\EventSourcing\Serialization;

use InvalidArgumentException;
use Ledger\EventSourcing\DomainEvent;

/**
 * Maps stable event type names to event classes.
 *
 * Renaming or moving a class must never break the stored history, so the
 * store only ever sees the type name.
 */
final class EventTypeRegistry
{
    /** @var array<string, class-string<DomainEvent>> */
    private array $classesByType = [];

    /**
     * @param  list<class-string<DomainEvent>>  $eventClasses
     */
    public function __construct(array $eventClasses = [])
    {
        foreach ($eventClasses as $eventClass) {
            $this->register($eventClass);
        }
    }

    /**
     * @param  class-string<DomainEvent>  $eventClass
     */
    public function register(string $eventClass): void
    {
        $type = $eventClass::eventType();

        if (isset($this->classesByType[$type]) && $this->classesByType[$type] !== $eventClass) {
            throw new InvalidArgumentException(sprintf(
                'Event type "%s" is already registered for %s.',
                $type,
                $this->classesByType[$type],
            ));
        }

        $this->classesByType[$type] = $eventClass;
    }

    /**
     * @return class-string<DomainEvent>
     */
    public function classFor(string $type): string
    {
        return $this->classesByType[$type] ?? throw UnknownEventType::named($type);
    }
}
