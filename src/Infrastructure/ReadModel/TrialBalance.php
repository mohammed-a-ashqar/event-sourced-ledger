<?php

declare(strict_types=1);

namespace Ledger\Infrastructure\ReadModel;

use Illuminate\Database\ConnectionInterface;
use Ledger\Infrastructure\Database\Row;

/**
 * Sums the general journal per ledger account and per currency.
 *
 * In a correct double-entry ledger, total debits equal total credits for
 * every currency, and the transfer clearing account is zero whenever no
 * transfer is in flight.
 *
 * @phpstan-type AccountTotals array{ledger_account: string, currency: string, debits: int, credits: int, balance: int}
 * @phpstan-type CurrencyTotals array{currency: string, debits: int, credits: int, balanced: bool}
 */
final readonly class TrialBalance
{
    public function __construct(private ConnectionInterface $db) {}

    /**
     * @return array{accounts: list<AccountTotals>, currencies: list<CurrencyTotals>}
     */
    public function compute(): array
    {
        $rows = $this->db->table('journal_lines')
            ->select(['ledger_account', 'currency', 'direction'])
            ->selectRaw('SUM(amount) AS total')
            ->groupBy(['ledger_account', 'currency', 'direction'])
            ->orderBy('currency')
            ->orderBy('ledger_account')
            ->get();

        /** @var array<string, AccountTotals> $accounts */
        $accounts = [];

        foreach ($rows as $row) {
            $key = Row::string($row, 'currency').'|'.Row::string($row, 'ledger_account');
            $accounts[$key] ??= [
                'ledger_account' => Row::string($row, 'ledger_account'),
                'currency' => Row::string($row, 'currency'),
                'debits' => 0,
                'credits' => 0,
                'balance' => 0,
            ];

            $side = Row::string($row, 'direction') === 'debit' ? 'debits' : 'credits';
            $accounts[$key][$side] += Row::int($row, 'total');
            $accounts[$key]['balance'] = $accounts[$key]['credits'] - $accounts[$key]['debits'];
        }

        return ['accounts' => array_values($accounts), 'currencies' => self::currencyTotals($accounts)];
    }

    /**
     * @param  array<string, AccountTotals>  $accounts
     * @return list<CurrencyTotals>
     */
    private static function currencyTotals(array $accounts): array
    {
        $totals = [];

        foreach ($accounts as $account) {
            $totals[$account['currency']] ??= ['currency' => $account['currency'], 'debits' => 0, 'credits' => 0, 'balanced' => true];
            $totals[$account['currency']]['debits'] += $account['debits'];
            $totals[$account['currency']]['credits'] += $account['credits'];
        }

        foreach ($totals as $currency => $total) {
            $totals[$currency]['balanced'] = $total['debits'] === $total['credits'];
        }

        return array_values($totals);
    }
}
