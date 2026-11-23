<?php

declare(strict_types=1);

namespace Ledger\EventSourcing;

/**
 * Tracks which request and which message caused the events being written.
 *
 * The command bus opens a scope per command (causation = command id). After
 * every save the repository moves the causation to the last written event,
 * so in a multi-step process each step is caused by the previous step.
 */
final class CorrelationContext
{
    private ?string $correlationId = null;

    private ?string $causationId = null;

    public function correlationId(): string
    {
        return $this->correlationId ??= Uuid::v4();
    }

    public function causationId(): ?string
    {
        return $this->causationId;
    }

    public function causedBy(string $messageId): void
    {
        $this->causationId = $messageId;
    }

    /**
     * Runs $work with the given ids and restores the previous ids afterwards.
     *
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    public function within(?string $correlationId, string $causationId, callable $work): mixed
    {
        [$previousCorrelation, $previousCausation] = [$this->correlationId, $this->causationId];

        $this->correlationId = $correlationId ?? $previousCorrelation ?? Uuid::v4();
        $this->causationId = $causationId;

        try {
            return $work();
        } finally {
            [$this->correlationId, $this->causationId] = [$previousCorrelation, $previousCausation];
        }
    }

    public function useCorrelationId(string $correlationId): void
    {
        $this->correlationId = $correlationId;
    }
}
