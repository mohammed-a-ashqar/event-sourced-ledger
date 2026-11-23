<?php

declare(strict_types=1);

namespace Ledger\Domain\Ledger;

use Ledger\Domain\Shared\BusinessRuleViolation;
use Ledger\Domain\Shared\Money;

final class UnbalancedJournalEntry extends BusinessRuleViolation
{
    public static function needsBothSides(): self
    {
        return new self('A journal entry needs at least one debit and one credit.');
    }

    public static function totalsDiffer(Money $debits, Money $credits): self
    {
        return new self(sprintf('Debits (%s) do not equal credits (%s).', $debits, $credits));
    }

    public function errorCode(): string
    {
        return 'unbalanced_journal_entry';
    }
}
