<?php

declare(strict_types=1);

namespace Ledger\Domain\Account\Event;

use Ledger\Domain\Account\AccountId;
use Ledger\Domain\Shared\Currency;
use Ledger\Domain\Shared\Money;
use Ledger\EventSourcing\DomainEvent;
use Ledger\EventSourcing\Payload;

/**
 * Schema history:
 *  v1 {account_id, owner, currency}
 *  v2 {account_id, owner_name, currency, overdraft_limit}  (field renamed, overdraft introduced)
 */
final readonly class AccountOpened implements DomainEvent
{
    public function __construct(
        public AccountId $accountId,
        public string $ownerName,
        public Currency $currency,
        public Money $overdraftLimit,
    ) {}

    public static function eventType(): string
    {
        return 'account.opened';
    }

    public static function schemaVersion(): int
    {
        return 2;
    }

    public function toPayload(): array
    {
        return [
            'account_id' => $this->accountId->value,
            'owner_name' => $this->ownerName,
            'currency' => $this->currency->code,
            'overdraft_limit' => $this->overdraftLimit->amount,
        ];
    }

    public static function fromPayload(array $payload): self
    {
        $currency = Currency::of(Payload::string($payload, 'currency'));

        return new self(
            AccountId::fromString(Payload::string($payload, 'account_id')),
            Payload::string($payload, 'owner_name'),
            $currency,
            Money::of(Payload::int($payload, 'overdraft_limit'), $currency),
        );
    }
}
