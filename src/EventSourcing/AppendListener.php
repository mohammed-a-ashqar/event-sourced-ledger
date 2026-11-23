<?php

declare(strict_types=1);

namespace Ledger\EventSourcing;

/**
 * Called by the event store inside the append transaction.
 *
 * Anything written here commits or rolls back together with the events,
 * which is what makes the transactional outbox reliable.
 */
interface AppendListener
{
    /**
     * @param  list<EventEnvelope>  $events  appended events, with positions
     */
    public function onAppend(array $events): void;
}
