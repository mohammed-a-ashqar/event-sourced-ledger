<?php

declare(strict_types=1);

namespace Ledger\Domain\Ledger;

use Ledger\Domain\Shared\Money;

/**
 * A balanced set of postings: the double-entry invariant lives here.
 *
 * An entry is only constructible if it has at least one debit and one
 * credit, every posting uses the same currency, and the debits sum to
 * exactly the credits.
 */
final readonly class JournalEntry
{
    /**
     * @param  non-empty-list<Posting>  $postings
     */
    private function __construct(
        public string $entryId,
        public array $postings,
    ) {}

    /**
     * @param  list<Posting>  $postings
     */
    public static function balanced(string $entryId, array $postings): self
    {
        if ($postings === []) {
            throw UnbalancedJournalEntry::needsBothSides();
        }

        $currency = $postings[0]->amount->currency;
        $debits = Money::zero($currency);
        $credits = Money::zero($currency);

        foreach ($postings as $posting) {
            if ($posting->direction === Direction::Debit) {
                $debits = $debits->add($posting->amount);
            } else {
                $credits = $credits->add($posting->amount);
            }
        }

        if ($debits->isZero() || $credits->isZero()) {
            throw UnbalancedJournalEntry::needsBothSides();
        }

        if (! $debits->equals($credits)) {
            throw UnbalancedJournalEntry::totalsDiffer($debits, $credits);
        }

        return new self($entryId, $postings);
    }
}
