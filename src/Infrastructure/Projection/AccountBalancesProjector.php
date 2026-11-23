<?php

declare(strict_types=1);

namespace Ledger\Infrastructure\Projection;

use Illuminate\Database\ConnectionInterface;
use Ledger\Domain\Account\AccountStatus;
use Ledger\Domain\Account\Event\AccountFrozen;
use Ledger\Domain\Account\Event\AccountOpened;
use Ledger\Domain\Account\Event\AccountUnfrozen;
use Ledger\Domain\Account\Event\MoneyDeposited;
use Ledger\Domain\Account\Event\MoneyWithdrawn;
use Ledger\Domain\Account\Event\TransferCredited;
use Ledger\Domain\Account\Event\TransferDebited;
use Ledger\Domain\Account\Event\TransferDebitReversed;
use Ledger\EventSourcing\EventEnvelope;
use Ledger\Infrastructure\Database\Timestamp;

/**
 * Current balance and status per account (the "account summary" read model).
 */
final readonly class AccountBalancesProjector implements Projector
{
    private const TABLE = 'account_balances';

    public function __construct(private ConnectionInterface $db) {}

    public function name(): string
    {
        return 'account_balances';
    }

    public function project(EventEnvelope $envelope): void
    {
        $event = $envelope->event;

        match (true) {
            $event instanceof AccountOpened => $this->db->table(self::TABLE)->insert([
                'account_id' => $event->accountId->value,
                'owner_name' => $event->ownerName,
                'currency' => $event->currency->code,
                'balance' => 0,
                'overdraft_limit' => $event->overdraftLimit->amount,
                'status' => AccountStatus::Active->value,
                'version' => $envelope->version,
                'opened_at' => Timestamp::toColumn($envelope->metadata->occurredAt),
                'updated_at' => Timestamp::toColumn($envelope->metadata->occurredAt),
            ]),
            $event instanceof MoneyDeposited,
            $event instanceof TransferCredited,
            $event instanceof TransferDebitReversed => $this->changeBalance($envelope, $event->accountId->value, $event->amount->amount),
            $event instanceof MoneyWithdrawn,
            $event instanceof TransferDebited => $this->changeBalance($envelope, $event->accountId->value, -$event->amount->amount),
            $event instanceof AccountFrozen => $this->update($envelope, $event->accountId->value, ['status' => AccountStatus::Frozen->value]),
            $event instanceof AccountUnfrozen => $this->update($envelope, $event->accountId->value, ['status' => AccountStatus::Active->value]),
            default => null,
        };
    }

    public function reset(): void
    {
        $this->db->table(self::TABLE)->delete();
    }

    private function changeBalance(EventEnvelope $envelope, string $accountId, int $delta): void
    {
        $this->db->table(self::TABLE)
            ->where('account_id', $accountId)
            ->increment('balance', $delta, $this->versionColumns($envelope));
    }

    /**
     * @param  array<string, string>  $columns
     */
    private function update(EventEnvelope $envelope, string $accountId, array $columns): void
    {
        $this->db->table(self::TABLE)
            ->where('account_id', $accountId)
            ->update($columns + $this->versionColumns($envelope));
    }

    /**
     * @return array{version: int, updated_at: string}
     */
    private function versionColumns(EventEnvelope $envelope): array
    {
        return [
            'version' => $envelope->version,
            'updated_at' => Timestamp::toColumn($envelope->metadata->occurredAt),
        ];
    }
}
