<?php

declare(strict_types=1);

namespace Ledger\Infrastructure\Outbox;

use Ledger\Application\Messaging\IntegrationMessage;
use Ledger\Domain\Account\Event\AccountFrozen;
use Ledger\Domain\Account\Event\AccountOpened;
use Ledger\Domain\Account\Event\MoneyDeposited;
use Ledger\Domain\Account\Event\MoneyWithdrawn;
use Ledger\Domain\Transfer\Event\TransferCompensated;
use Ledger\Domain\Transfer\Event\TransferCompleted;
use Ledger\Domain\Transfer\Event\TransferRejected;
use Ledger\EventSourcing\EventEnvelope;

/**
 * Decides which domain events leave the service, and in which public shape.
 *
 * Intermediate saga steps (debits, clearing movements) stay internal; the
 * outside world only learns about outcomes.
 */
final class IntegrationEventMapper
{
    public function map(EventEnvelope $envelope): ?IntegrationMessage
    {
        $event = $envelope->event;

        [$type, $payload] = match (true) {
            $event instanceof AccountOpened => ['ledger.account.opened.v1', [
                'account_id' => $event->accountId->value,
                'owner_name' => $event->ownerName,
                'currency' => $event->currency->code,
            ]],
            $event instanceof AccountFrozen => ['ledger.account.frozen.v1', [
                'account_id' => $event->accountId->value,
                'reason' => $event->reason,
            ]],
            $event instanceof MoneyDeposited => ['ledger.funds.deposited.v1', [
                'account_id' => $event->accountId->value,
                'transaction_id' => $event->transactionId->value,
                'amount' => $event->amount->amount,
                'currency' => $event->amount->currency->code,
            ]],
            $event instanceof MoneyWithdrawn => ['ledger.funds.withdrawn.v1', [
                'account_id' => $event->accountId->value,
                'transaction_id' => $event->transactionId->value,
                'amount' => $event->amount->amount,
                'currency' => $event->amount->currency->code,
            ]],
            $event instanceof TransferCompleted => ['ledger.transfer.completed.v1', ['transfer_id' => $event->transferId->value] + $event->terms->toPayload()],
            $event instanceof TransferRejected,
            $event instanceof TransferCompensated => ['ledger.transfer.failed.v1', ['transfer_id' => $event->transferId->value] + $event->terms->toPayload() + [
                'outcome' => $event instanceof TransferRejected ? 'rejected' : 'compensated',
                'reason' => $event->reason,
            ]],
            default => [null, []],
        };

        if ($type === null) {
            return null;
        }

        return new IntegrationMessage(
            $envelope->eventId(),
            $type,
            $payload,
            [
                'correlation_id' => $envelope->metadata->correlationId,
                'causation_id' => $envelope->eventId(),
                'stream_id' => $envelope->streamId,
            ],
            $envelope->metadata->occurredAt,
        );
    }
}
