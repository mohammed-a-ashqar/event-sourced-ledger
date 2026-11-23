<?php

declare(strict_types=1);

namespace Ledger\Domain\Account\Event;

use Ledger\Domain\Account\AccountId;
use Ledger\EventSourcing\DomainEvent;
use Ledger\EventSourcing\Payload;

final readonly class AccountFrozen implements DomainEvent
{
    public function __construct(
        public AccountId $accountId,
        public string $reason,
    ) {}

    public static function eventType(): string
    {
        return 'account.frozen';
    }

    public static function schemaVersion(): int
    {
        return 1;
    }

    public function toPayload(): array
    {
        return ['account_id' => $this->accountId->value, 'reason' => $this->reason];
    }

    public static function fromPayload(array $payload): self
    {
        return new self(
            AccountId::fromString(Payload::string($payload, 'account_id')),
            Payload::string($payload, 'reason'),
        );
    }
}
