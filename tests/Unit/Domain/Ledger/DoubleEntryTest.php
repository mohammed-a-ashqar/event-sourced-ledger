<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Ledger;

use Ledger\Domain\Account\Event\AccountFrozen;
use Ledger\Domain\Account\Event\MoneyWithdrawn;
use Ledger\Domain\Account\Event\TransferCredited;
use Ledger\Domain\Account\Event\TransferDebited;
use Ledger\Domain\Account\Event\TransferDebitReversed;
use Ledger\Domain\Ledger\Direction;
use Ledger\Domain\Ledger\JournalEntry;
use Ledger\Domain\Ledger\LedgerAccount;
use Ledger\Domain\Ledger\Posting;
use Ledger\Domain\Ledger\PostingRules;
use Ledger\Domain\Ledger\UnbalancedJournalEntry;
use Ledger\Domain\Shared\Currency;
use Ledger\Domain\Shared\CurrencyMismatch;
use Ledger\Domain\Shared\InvalidAmount;
use Ledger\Domain\Shared\Money;
use Ledger\EventSourcing\DomainEvent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Support\Fixtures as F;

final class DoubleEntryTest extends TestCase
{
    #[Test]
    public function a_balanced_entry_is_accepted(): void
    {
        $cash = LedgerAccount::cash(Currency::of('EUR'));

        $entry = JournalEntry::balanced('e1', [
            Posting::debit($cash, F::eur(1_000)),
            Posting::credit(LedgerAccount::customer(F::alice()), F::eur(600)),
            Posting::credit(LedgerAccount::customer(F::bob()), F::eur(400)),
        ]);

        self::assertCount(3, $entry->postings);
    }

    #[Test]
    public function debits_must_equal_credits(): void
    {
        $this->expectException(UnbalancedJournalEntry::class);
        $this->expectExceptionMessage('Debits (10.00 EUR) do not equal credits (9.99 EUR)');

        JournalEntry::balanced('e1', [
            Posting::debit(LedgerAccount::cash(Currency::of('EUR')), F::eur(1_000)),
            Posting::credit(LedgerAccount::customer(F::alice()), F::eur(999)),
        ]);
    }

    #[Test]
    public function an_entry_needs_both_sides(): void
    {
        $this->expectException(UnbalancedJournalEntry::class);

        JournalEntry::balanced('e1', [Posting::debit(LedgerAccount::cash(Currency::of('EUR')), F::eur(1_000))]);
    }

    #[Test]
    public function an_empty_entry_is_rejected(): void
    {
        $this->expectException(UnbalancedJournalEntry::class);

        JournalEntry::balanced('e1', []);
    }

    #[Test]
    public function an_entry_cannot_mix_currencies(): void
    {
        $this->expectException(CurrencyMismatch::class);

        JournalEntry::balanced('e1', [
            Posting::debit(LedgerAccount::cash(Currency::of('EUR')), F::eur(1_000)),
            Posting::credit(LedgerAccount::customer(F::alice()), Money::of(1_000, 'USD')),
        ]);
    }

    #[Test]
    public function postings_are_always_positive_the_direction_carries_the_sign(): void
    {
        $this->expectException(InvalidAmount::class);

        Posting::debit(LedgerAccount::cash(Currency::of('EUR')), F::eur(-5));
    }

    /**
     * @return iterable<string, array{DomainEvent, string, string}>
     */
    public static function moneyEvents(): iterable
    {
        $alice = 'customer:'.F::ALICE;
        $bob = 'customer:'.F::BOB;

        yield 'deposit' => [F::deposited(500), 'system:cash:EUR', $alice];
        yield 'withdrawal' => [new MoneyWithdrawn(F::alice(), F::transactionId(), F::eur(500), null), $alice, 'system:cash:EUR'];
        yield 'transfer debit' => [new TransferDebited(F::alice(), F::transferId(), F::eur(500), F::bob()), $alice, 'system:transfer-clearing:EUR'];
        yield 'transfer credit' => [new TransferCredited(F::bob(), F::transferId(), F::eur(500), F::alice()), 'system:transfer-clearing:EUR', $bob];
        yield 'transfer reversal' => [new TransferDebitReversed(F::alice(), F::transferId(), F::eur(500), F::bob()), 'system:transfer-clearing:EUR', $alice];
    }

    #[Test]
    #[DataProvider('moneyEvents')]
    public function every_money_event_becomes_a_balanced_two_line_entry(DomainEvent $event, string $debited, string $credited): void
    {
        $entry = PostingRules::journalEntryFor($event, 'entry-1');

        self::assertNotNull($entry);
        self::assertSame('entry-1', $entry->entryId);
        self::assertSame(
            [[$debited, Direction::Debit, 500], [$credited, Direction::Credit, 500]],
            array_map(fn (Posting $p) => [$p->account->code, $p->direction, $p->amount->amount], $entry->postings),
        );
    }

    #[Test]
    public function events_that_move_no_money_produce_no_entry(): void
    {
        self::assertNull(PostingRules::journalEntryFor(F::opened(), 'e'));
        self::assertNull(PostingRules::journalEntryFor(new AccountFrozen(F::alice(), 'x'), 'e'));
    }

    #[Test]
    public function a_compensated_transfer_leaves_the_clearing_account_at_zero(): void
    {
        $events = [
            new TransferDebited(F::alice(), F::transferId(), F::eur(500), F::bob()),
            new TransferDebitReversed(F::alice(), F::transferId(), F::eur(500), F::bob()),
        ];

        $balances = [];

        foreach ($events as $i => $event) {
            $entry = PostingRules::journalEntryFor($event, 'e'.$i);
            self::assertNotNull($entry);

            foreach ($entry->postings as $posting) {
                $signed = $posting->direction === Direction::Credit ? $posting->amount->amount : -$posting->amount->amount;
                $balances[$posting->account->code] = ($balances[$posting->account->code] ?? 0) + $signed;
            }
        }

        self::assertSame(['customer:'.F::ALICE => 0, 'system:transfer-clearing:EUR' => 0], $balances);
    }
}
