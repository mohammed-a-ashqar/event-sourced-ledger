<?php

declare(strict_types=1);

namespace Ledger\Domain\Transfer;

use Ledger\Domain\Account\AccountId;
use Ledger\Domain\Shared\InvalidAmount;
use Ledger\Domain\Shared\Money;
use Ledger\Domain\Transfer\Exception\InvalidTransfer;
use Ledger\EventSourcing\Payload;

/**
 * What is moved, from where, to where.
 */
final readonly class TransferTerms
{
    public function __construct(
        public AccountId $source,
        public AccountId $destination,
        public Money $amount,
    ) {
        if ($source->equals($destination)) {
            throw InvalidTransfer::sameAccount();
        }

        if (! $amount->isPositive()) {
            throw InvalidAmount::mustBePositive($amount);
        }
    }

    public function equals(self $other): bool
    {
        return $this->source->equals($other->source)
            && $this->destination->equals($other->destination)
            && $this->amount->equals($other->amount);
    }

    /**
     * @return array{source_account_id: string, destination_account_id: string, amount: int, currency: string}
     */
    public function toPayload(): array
    {
        return [
            'source_account_id' => $this->source->value,
            'destination_account_id' => $this->destination->value,
            'amount' => $this->amount->amount,
            'currency' => $this->amount->currency->code,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromPayload(array $payload): self
    {
        return new self(
            AccountId::fromString(Payload::string($payload, 'source_account_id')),
            AccountId::fromString(Payload::string($payload, 'destination_account_id')),
            Money::of(Payload::int($payload, 'amount'), Payload::string($payload, 'currency')),
        );
    }
}
