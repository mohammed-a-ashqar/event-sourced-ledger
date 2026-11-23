<?php

declare(strict_types=1);

namespace Ledger\Infrastructure\ReadModel;

use Illuminate\Database\ConnectionInterface;
use Ledger\Infrastructure\Database\Row;
use Ledger\Infrastructure\Database\Timestamp;

/**
 * Query side for accounts: reads only from projected tables, never from the event store.
 *
 * @phpstan-type MoneyShape array{amount: int, currency: string, formatted: string}
 * @phpstan-type AccountShape array{id: string, owner_name: string, status: string, balance: MoneyShape, overdraft_limit: MoneyShape, available: MoneyShape, version: int, opened_at: string, updated_at: string}
 * @phpstan-type StatementLineShape array{position: int, type: string, amount: MoneyShape, balance_after: MoneyShape, reference_id: string, description: string|null, occurred_at: string}
 */
final readonly class AccountReadModel
{
    public function __construct(private ConnectionInterface $db) {}

    /**
     * @return AccountShape|null
     */
    public function find(string $accountId): ?array
    {
        $row = $this->db->table('account_balances')->where('account_id', $accountId)->first();

        if (! is_object($row)) {
            return null;
        }

        $currency = Row::string($row, 'currency');
        $balance = Row::int($row, 'balance');
        $overdraft = Row::int($row, 'overdraft_limit');

        return [
            'id' => Row::string($row, 'account_id'),
            'owner_name' => Row::string($row, 'owner_name'),
            'status' => Row::string($row, 'status'),
            'balance' => MoneyView::of($balance, $currency),
            'overdraft_limit' => MoneyView::of($overdraft, $currency),
            'available' => MoneyView::of($balance + $overdraft, $currency),
            'version' => Row::int($row, 'version'),
            'opened_at' => Timestamp::toIso8601(Row::string($row, 'opened_at')),
            'updated_at' => Timestamp::toIso8601(Row::string($row, 'updated_at')),
        ];
    }

    /**
     * Keyset pagination on the global position: stable while new lines arrive.
     *
     * @return list<StatementLineShape>
     */
    public function statement(string $accountId, int $afterPosition, int $limit): array
    {
        $rows = $this->db->table('account_statement_lines')
            ->where('account_id', $accountId)
            ->where('position', '>', $afterPosition)
            ->orderBy('position')
            ->limit($limit)
            ->get();

        $lines = [];

        foreach ($rows as $row) {
            $currency = Row::string($row, 'currency');

            $lines[] = [
                'position' => Row::int($row, 'position'),
                'type' => Row::string($row, 'entry_type'),
                'amount' => MoneyView::of(Row::int($row, 'amount'), $currency),
                'balance_after' => MoneyView::of(Row::int($row, 'balance_after'), $currency),
                'reference_id' => Row::string($row, 'reference_id'),
                'description' => Row::nullableString($row, 'description'),
                'occurred_at' => Timestamp::toIso8601(Row::string($row, 'occurred_at')),
            ];
        }

        return $lines;
    }
}
