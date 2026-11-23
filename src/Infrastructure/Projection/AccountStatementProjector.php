<?php

declare(strict_types=1);

namespace Ledger\Infrastructure\Projection;

use Illuminate\Database\ConnectionInterface;
use Ledger\Domain\Account\Event\MoneyDeposited;
use Ledger\Domain\Account\Event\MoneyWithdrawn;
use Ledger\Domain\Account\Event\TransferCredited;
use Ledger\Domain\Account\Event\TransferDebited;
use Ledger\Domain\Account\Event\TransferDebitReversed;
use Ledger\Domain\Shared\Money;
use Ledger\EventSourcing\EventEnvelope;
use Ledger\Infrastructure\Database\Row;
use Ledger\Infrastructure\Database\Timestamp;

/**
 * One line per money movement, with the running balance after it
 * (what a bank statement shows).
 */
final readonly class AccountStatementProjector implements Projector
{
    private const TABLE = 'account_statement_lines';

    public function __construct(private ConnectionInterface $db) {}

    public function name(): string
    {
        return 'account_statement';
    }

    public function project(EventEnvelope $envelope): void
    {
        $event = $envelope->event;

        match (true) {
            $event instanceof MoneyDeposited => $this->addLine($envelope, $event->accountId->value, 'deposit', $event->amount, $event->transactionId->value, $event->reference),
            $event instanceof MoneyWithdrawn => $this->addLine($envelope, $event->accountId->value, 'withdrawal', $event->amount->negate(), $event->transactionId->value, $event->reference),
            $event instanceof TransferDebited => $this->addLine($envelope, $event->accountId->value, 'transfer_out', $event->amount->negate(), $event->transferId->value, 'Transfer to '.$event->counterpartyAccountId),
            $event instanceof TransferCredited => $this->addLine($envelope, $event->accountId->value, 'transfer_in', $event->amount, $event->transferId->value, 'Transfer from '.$event->counterpartyAccountId),
            $event instanceof TransferDebitReversed => $this->addLine($envelope, $event->accountId->value, 'transfer_reversal', $event->amount, $event->transferId->value, 'Reversal of transfer to '.$event->counterpartyAccountId),
            default => null,
        };
    }

    public function reset(): void
    {
        $this->db->table(self::TABLE)->delete();
    }

    private function addLine(EventEnvelope $envelope, string $accountId, string $type, Money $signedAmount, string $referenceId, ?string $description): void
    {
        $this->db->table(self::TABLE)->insert([
            'account_id' => $accountId,
            'event_id' => $envelope->eventId(),
            'position' => $envelope->position,
            'entry_type' => $type,
            'amount' => $signedAmount->amount,
            'balance_after' => $this->lastBalance($accountId) + $signedAmount->amount,
            'currency' => $signedAmount->currency->code,
            'reference_id' => $referenceId,
            'description' => $description,
            'occurred_at' => Timestamp::toColumn($envelope->metadata->occurredAt),
        ]);
    }

    private function lastBalance(string $accountId): int
    {
        $last = $this->db->table(self::TABLE)
            ->where('account_id', $accountId)
            ->orderByDesc('position')
            ->first(['balance_after']);

        return is_object($last) ? Row::int($last, 'balance_after') : 0;
    }
}
