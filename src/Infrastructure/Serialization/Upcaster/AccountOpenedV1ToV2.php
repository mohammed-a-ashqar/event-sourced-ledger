<?php

declare(strict_types=1);

namespace Ledger\Infrastructure\Serialization\Upcaster;

use Ledger\EventSourcing\Payload;
use Ledger\EventSourcing\Upcasting\Upcaster;

/**
 * v1 called the field "owner" and had no overdraft facility.
 * v2 renames it to "owner_name" and adds "overdraft_limit": every account
 * opened before overdrafts existed simply has a limit of zero.
 */
final class AccountOpenedV1ToV2 implements Upcaster
{
    public function eventType(): string
    {
        return 'account.opened';
    }

    public function fromVersion(): int
    {
        return 1;
    }

    public function upcast(array $payload): array
    {
        return [
            'account_id' => Payload::string($payload, 'account_id'),
            'owner_name' => Payload::string($payload, 'owner'),
            'currency' => Payload::string($payload, 'currency'),
            'overdraft_limit' => 0,
        ];
    }
}
