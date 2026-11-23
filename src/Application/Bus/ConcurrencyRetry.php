<?php

declare(strict_types=1);

namespace Ledger\Application\Bus;

use InvalidArgumentException;
use Ledger\EventSourcing\ConcurrencyException;

/**
 * Re-runs a unit of work when it lost an optimistic concurrency race.
 *
 * The work must reload its aggregates on every attempt; that is what makes
 * a retry see the competing writer's events and decide again.
 */
final readonly class ConcurrencyRetry
{
    public function __construct(private int $maxAttempts)
    {
        if ($maxAttempts < 1) {
            throw new InvalidArgumentException('At least one attempt is required.');
        }
    }

    /**
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    public function run(callable $work): mixed
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return $work();
            } catch (ConcurrencyException $conflict) {
                if ($attempt >= $this->maxAttempts) {
                    throw $conflict;
                }
            }
        }
    }
}
