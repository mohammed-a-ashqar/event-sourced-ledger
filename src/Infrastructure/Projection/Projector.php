<?php

declare(strict_types=1);

namespace Ledger\Infrastructure\Projection;

use Ledger\EventSourcing\EventEnvelope;

/**
 * Builds one read model from the global event log.
 *
 * project() is called once per event, in position order, inside the same
 * transaction that advances the projector's checkpoint.
 */
interface Projector
{
    public function name(): string;

    public function project(EventEnvelope $envelope): void;

    /**
     * Empties the read model before a rebuild.
     */
    public function reset(): void;
}
