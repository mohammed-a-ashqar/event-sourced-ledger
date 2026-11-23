<?php

declare(strict_types=1);

namespace Ledger\Domain\Account\Event;

use Ledger\Domain\Account\AccountId;
use Ledger\Domain\Shared\Money;
use Ledger\Domain\Shared\TransactionId;
use Ledger\EventSourcing\DomainEvent;
use Ledger\EventSourcing\Payload;

/**
 * Schema history:
 *  v1 {account_id, transaction_id, amount: "12.50" (decimal string), currency}
 *  v2 {account_id, transaction_id, amount: 1250 (minor units), currency, reference}
 */
final readonly class MoneyDeposited implements DomainEvent
{
    public function __construct(
        public AccountId $accountId,
        public TransactionId $transactionId,
        public Money $amount,
        public ?string $reference,
    ) {}

    public static function eventType(): string
    {
        return 'account.money_deposited';
    }

    public static function schemaVersion(): int
    {
        return 2;
    }

    public function toPayload(): array
    {
        return [
            'account_id' => $this->accountId->value,
            'transaction_id' => $this->transactionId->value,
            'amount' => $this->amount->amount,
            'currency' => $this->amount->currency->code,
            'reference' => $this->reference,
        ];
    }

    public static function fromPayload(array $payload): self
    {
        return new self(
            AccountId::fromString(Payload::string($payload, 'account_id')),
            TransactionId::fromString(Payload::string($payload, 'transaction_id')),
            Money::of(Payload::int($payload, 'amount'), Payload::string($payload, 'currency')),
            Payload::nullableString($payload, 'reference'),
        );
    }
}
