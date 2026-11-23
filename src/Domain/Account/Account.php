<?php

declare(strict_types=1);

namespace Ledger\Domain\Account;

use Ledger\Domain\Account\Event\AccountFrozen;
use Ledger\Domain\Account\Event\AccountOpened;
use Ledger\Domain\Account\Event\AccountUnfrozen;
use Ledger\Domain\Account\Event\MoneyDeposited;
use Ledger\Domain\Account\Event\MoneyWithdrawn;
use Ledger\Domain\Account\Event\TransferCredited;
use Ledger\Domain\Account\Event\TransferDebited;
use Ledger\Domain\Account\Event\TransferDebitReversed;
use Ledger\Domain\Account\Event\TransferLegEvent;
use Ledger\Domain\Account\Exception\AccountIsFrozen;
use Ledger\Domain\Account\Exception\AccountIsNotFrozen;
use Ledger\Domain\Account\Exception\InsufficientFunds;
use Ledger\Domain\Account\Exception\InvalidAccountDetails;
use Ledger\Domain\Account\Exception\TransferLegMismatch;
use Ledger\Domain\Shared\Currency;
use Ledger\Domain\Shared\InvalidAmount;
use Ledger\Domain\Shared\Money;
use Ledger\Domain\Shared\TransactionId;
use Ledger\Domain\Transfer\TransferId;
use Ledger\EventSourcing\AggregateRoot;
use Ledger\EventSourcing\DomainEvent;
use Ledger\EventSourcing\MalformedPayload;
use Ledger\EventSourcing\Payload;
use Ledger\EventSourcing\Snapshot\Snapshotable;
use LogicException;

/**
 * A customer wallet in a single currency.
 *
 * Invariants:
 *  - every amount moved is positive and in the account's currency;
 *  - the balance never drops below minus the overdraft limit;
 *  - a frozen account accepts no debits or credits, except the reversal of
 *    one of its own transfer debits (giving money back never breaks a rule);
 *  - each transfer leg is applied at most once, which makes the transfer
 *    saga safe to retry.
 */
final class Account extends AggregateRoot implements Snapshotable
{
    private const MAX_OWNER_NAME_LENGTH = 120;

    private const MAX_REASON_LENGTH = 255;

    private AccountId $id;

    private string $ownerName;

    private Money $balance;

    private Money $overdraftLimit;

    private AccountStatus $status;

    /** @var array<string, int> "<leg>:<transfer id>" => amount in minor units */
    private array $transferLegs = [];

    public static function open(AccountId $id, string $ownerName, Currency $currency, Money $overdraftLimit): self
    {
        $ownerName = trim($ownerName);

        if ($ownerName === '' || mb_strlen($ownerName) > self::MAX_OWNER_NAME_LENGTH) {
            throw InvalidAccountDetails::ownerName();
        }

        $overdraftLimit->assertSameCurrency(Money::zero($currency));

        if ($overdraftLimit->isNegative()) {
            throw InvalidAmount::mustNotBeNegative($overdraftLimit);
        }

        $account = new self;
        $account->recordThat(new AccountOpened($id, $ownerName, $currency, $overdraftLimit));

        return $account;
    }

    public function deposit(TransactionId $transactionId, Money $amount, ?string $reference = null): void
    {
        $this->assertActive();
        $this->assertMovableAmount($amount);

        $this->recordThat(new MoneyDeposited($this->id, $transactionId, $amount, $reference));
    }

    public function withdraw(TransactionId $transactionId, Money $amount, ?string $reference = null): void
    {
        $this->assertActive();
        $this->assertMovableAmount($amount);
        $this->assertCanBeDebited($amount);

        $this->recordThat(new MoneyWithdrawn($this->id, $transactionId, $amount, $reference));
    }

    public function debitForTransfer(TransferId $transferId, Money $amount, AccountId $destination): void
    {
        if ($this->legAlreadyApplied(TransferDebited::class, $transferId, $amount)) {
            return;
        }

        $this->assertActive();
        $this->assertMovableAmount($amount);
        $this->assertCanBeDebited($amount);

        $this->recordThat(new TransferDebited($this->id, $transferId, $amount, $destination));
    }

    public function creditFromTransfer(TransferId $transferId, Money $amount, AccountId $source): void
    {
        if ($this->legAlreadyApplied(TransferCredited::class, $transferId, $amount)) {
            return;
        }

        $this->assertActive();
        $this->assertMovableAmount($amount);

        $this->recordThat(new TransferCredited($this->id, $transferId, $amount, $source));
    }

    /**
     * Compensating action of the transfer saga.
     */
    public function reverseTransferDebit(TransferId $transferId, Money $amount, AccountId $destination): void
    {
        if ($this->legAlreadyApplied(TransferDebitReversed::class, $transferId, $amount)) {
            return;
        }

        if (! $this->legAlreadyApplied(TransferDebited::class, $transferId, $amount)) {
            throw TransferLegMismatch::notDebited($this->id, $transferId);
        }

        $this->recordThat(new TransferDebitReversed($this->id, $transferId, $amount, $destination));
    }

    public function freeze(string $reason): void
    {
        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > self::MAX_REASON_LENGTH) {
            throw InvalidAccountDetails::freezeReason();
        }

        $this->assertActive();

        $this->recordThat(new AccountFrozen($this->id, $reason));
    }

    public function unfreeze(): void
    {
        if ($this->status !== AccountStatus::Frozen) {
            throw AccountIsNotFrozen::withId($this->id);
        }

        $this->recordThat(new AccountUnfrozen($this->id));
    }

    public function aggregateId(): string
    {
        return $this->id->value;
    }

    public function id(): AccountId
    {
        return $this->id;
    }

    public function ownerName(): string
    {
        return $this->ownerName;
    }

    public function currency(): Currency
    {
        return $this->balance->currency;
    }

    public function balance(): Money
    {
        return $this->balance;
    }

    public function overdraftLimit(): Money
    {
        return $this->overdraftLimit;
    }

    public function status(): AccountStatus
    {
        return $this->status;
    }

    /**
     * How much can still be debited: balance plus the overdraft limit.
     */
    public function available(): Money
    {
        return $this->balance->add($this->overdraftLimit);
    }

    private function assertActive(): void
    {
        if ($this->status === AccountStatus::Frozen) {
            throw AccountIsFrozen::withId($this->id);
        }
    }

    private function assertMovableAmount(Money $amount): void
    {
        $this->balance->assertSameCurrency($amount);

        if (! $amount->isPositive()) {
            throw InvalidAmount::mustBePositive($amount);
        }
    }

    private function assertCanBeDebited(Money $amount): void
    {
        if ($this->available()->isLessThan($amount)) {
            throw InsufficientFunds::toDebit($this->id, $amount, $this->available());
        }
    }

    /**
     * @param  class-string<TransferLegEvent>  $leg
     */
    private function legAlreadyApplied(string $leg, TransferId $transferId, Money $amount): bool
    {
        $applied = $this->transferLegs[self::legKey($leg, $transferId)] ?? null;

        if ($applied === null) {
            return false;
        }

        if ($applied !== $amount->amount || ! $amount->currency->equals($this->currency())) {
            throw TransferLegMismatch::differentAmount($this->id, $transferId);
        }

        return true;
    }

    /**
     * @param  class-string<TransferLegEvent>  $leg
     */
    private static function legKey(string $leg, TransferId $transferId): string
    {
        return $leg::eventType().':'.$transferId->value;
    }

    protected function apply(DomainEvent $event): void
    {
        match (true) {
            $event instanceof AccountOpened => $this->applyAccountOpened($event),
            $event instanceof MoneyDeposited => $this->balance = $this->balance->add($event->amount),
            $event instanceof MoneyWithdrawn => $this->balance = $this->balance->subtract($event->amount),
            $event instanceof TransferDebited => $this->applyTransferLeg($event, $event->amount->negate()),
            $event instanceof TransferCredited,
            $event instanceof TransferDebitReversed => $this->applyTransferLeg($event, $event->amount),
            $event instanceof AccountFrozen => $this->status = AccountStatus::Frozen,
            $event instanceof AccountUnfrozen => $this->status = AccountStatus::Active,
            default => throw new LogicException(sprintf('Account cannot apply %s.', $event::class)),
        };
    }

    private function applyAccountOpened(AccountOpened $event): void
    {
        $this->id = $event->accountId;
        $this->ownerName = $event->ownerName;
        $this->balance = Money::zero($event->currency);
        $this->overdraftLimit = $event->overdraftLimit;
        $this->status = AccountStatus::Active;
    }

    private function applyTransferLeg(TransferLegEvent $event, Money $balanceChange): void
    {
        $this->balance = $this->balance->add($balanceChange);
        $this->transferLegs[self::legKey($event::class, $event->transferId)] = $event->amount->amount;
    }

    public static function snapshotSchemaVersion(): int
    {
        return 1;
    }

    public function snapshotState(): array
    {
        return [
            'id' => $this->id->value,
            'owner_name' => $this->ownerName,
            'currency' => $this->currency()->code,
            'balance' => $this->balance->amount,
            'overdraft_limit' => $this->overdraftLimit->amount,
            'status' => $this->status->value,
            'transfer_legs' => $this->transferLegs,
        ];
    }

    public static function restoreFromSnapshot(array $state, int $version): static
    {
        $currency = Currency::of(Payload::string($state, 'currency'));

        $account = self::blankAtVersion($version);
        $account->id = AccountId::fromString(Payload::string($state, 'id'));
        $account->ownerName = Payload::string($state, 'owner_name');
        $account->balance = Money::of(Payload::int($state, 'balance'), $currency);
        $account->overdraftLimit = Money::of(Payload::int($state, 'overdraft_limit'), $currency);
        $account->status = AccountStatus::from(Payload::string($state, 'status'));
        $account->transferLegs = self::transferLegsFrom($state['transfer_legs'] ?? null);

        return $account;
    }

    /**
     * @return array<string, int>
     */
    private static function transferLegsFrom(mixed $legs): array
    {
        if (! is_array($legs)) {
            throw MalformedPayload::expected('transfer_legs', 'a map of amounts', $legs);
        }

        $parsed = [];

        foreach ($legs as $key => $amount) {
            if (! is_string($key) || ! is_int($amount)) {
                throw MalformedPayload::expected('transfer_legs', 'a map of amounts', $legs);
            }

            $parsed[$key] = $amount;
        }

        return $parsed;
    }
}
