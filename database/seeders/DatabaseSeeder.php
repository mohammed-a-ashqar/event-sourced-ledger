<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Ledger\Application\Account\DepositMoney;
use Ledger\Application\Account\OpenAccount;
use Ledger\Application\Bus\CommandBus;
use Ledger\Domain\Account\AccountId;
use Ledger\Domain\Shared\TransactionId;

/**
 * Seeds two demo accounts through the command bus, so every row in the
 * read models is derived from real events.
 */
final class DatabaseSeeder extends Seeder
{
    public function run(CommandBus $bus): void
    {
        $alice = AccountId::generate();
        $bob = AccountId::generate();

        $bus->dispatch(new OpenAccount($alice, 'Alice', 'EUR', 0));
        $bus->dispatch(new OpenAccount($bob, 'Bob', 'EUR', 5_000));
        $bus->dispatch(new DepositMoney($alice, TransactionId::generate(), 100_00, 'EUR', 'Opening balance'));

        $this->command->info(sprintf('Seeded accounts: Alice %s, Bob %s', $alice, $bob));
    }
}
