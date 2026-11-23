<?php

declare(strict_types=1);

namespace Ledger\Infrastructure\Messaging;

use Ledger\Application\Messaging\IntegrationMessage;
use Ledger\Application\Messaging\MessagePublisher;
use RuntimeException;

/**
 * In-process adapter that records what was published; it can be told to
 * reject certain message types to exercise the relay's failure handling.
 */
final class InMemoryMessagePublisher implements MessagePublisher
{
    /** @var list<IntegrationMessage> */
    private array $published = [];

    /** @var array<string, true> */
    private array $failingTypes = [];

    public function publish(IntegrationMessage $message): void
    {
        if (isset($this->failingTypes[$message->type])) {
            throw new RuntimeException(sprintf('Broker rejected %s.', $message->type));
        }

        $this->published[] = $message;
    }

    public function failFor(string $messageType): void
    {
        $this->failingTypes[$messageType] = true;
    }

    public function recover(): void
    {
        $this->failingTypes = [];
    }

    /**
     * @return list<IntegrationMessage>
     */
    public function published(): array
    {
        return $this->published;
    }
}
