<?php

declare(strict_types=1);

namespace Ledger\Domain\Account\Event;

use Ledger\Domain\Account\AccountId;
use Ledger\Domain\Shared\Money;
use Ledger\Domain\Transfer\TransferId;
use Ledger\EventSourcing\DomainEvent;
use Ledger\EventSourcing\Payload;

/**
 * Shared shape of the account-side events of a transfer (one leg each).
 */
abstract readonly class TransferLegEvent implements DomainEvent
{
    final public function __construct(
        public AccountId $accountId,
        public TransferId $transferId,
        public Money $amount,
        public AccountId $counterpartyAccountId,
    ) {}

    public static function schemaVersion(): int
    {
        return 1;
    }

    public function toPayload(): array
    {
        return [
            'account_id' => $this->accountId->value,
            'transfer_id' => $this->transferId->value,
            'amount' => $this->amount->amount,
            'currency' => $this->amount->currency->code,
            'counterparty_account_id' => $this->counterpartyAccountId->value,
        ];
    }

    public static function fromPayload(array $payload): static
    {
        return new static(
            AccountId::fromString(Payload::string($payload, 'account_id')),
            TransferId::fromString(Payload::string($payload, 'transfer_id')),
            Money::of(Payload::int($payload, 'amount'), Payload::string($payload, 'currency')),
            AccountId::fromString(Payload::string($payload, 'counterparty_account_id')),
        );
    }
}
