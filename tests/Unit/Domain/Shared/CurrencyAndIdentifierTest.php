<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Shared;

use Ledger\Domain\Account\AccountId;
use Ledger\Domain\Shared\Currency;
use Ledger\Domain\Shared\InvalidIdentifier;
use Ledger\Domain\Shared\UnsupportedCurrency;
use Ledger\Domain\Transfer\TransferId;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CurrencyAndIdentifierTest extends TestCase
{
    #[Test]
    public function currencies_know_their_iso_exponent(): void
    {
        self::assertSame(2, Currency::of('EUR')->minorUnits());
        self::assertSame(0, Currency::of('JPY')->minorUnits());
        self::assertSame(3, Currency::of('OMR')->minorUnits());
    }

    #[Test]
    public function unknown_or_lowercase_codes_are_rejected(): void
    {
        foreach (['usd', 'XXX', 'EURO', ''] as $code) {
            try {
                Currency::of($code);
                self::fail(sprintf('"%s" should have been rejected.', $code));
            } catch (UnsupportedCurrency) {
                $this->addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function identifiers_are_normalised_uuids(): void
    {
        $id = AccountId::fromString('3F2504E0-4F89-41D3-9A0C-0305E82C3301');

        self::assertSame('3f2504e0-4f89-41d3-9a0c-0305e82c3301', (string) $id);
    }

    #[Test]
    public function generated_identifiers_are_valid_v4_uuids_and_unique(): void
    {
        $first = AccountId::generate();
        $second = AccountId::generate();

        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $first->value);
        self::assertFalse($first->equals($second));
    }

    #[Test]
    public function identifiers_of_different_types_are_never_equal(): void
    {
        $value = '3f2504e0-4f89-41d3-9a0c-0305e82c3301';

        self::assertTrue(AccountId::fromString($value)->equals(AccountId::fromString($value)));
        self::assertFalse(AccountId::fromString($value)->equals(TransferId::fromString($value)));
    }

    #[Test]
    public function malformed_identifiers_are_rejected(): void
    {
        $this->expectException(InvalidIdentifier::class);
        $this->expectExceptionMessage('not a valid AccountId');

        AccountId::fromString('not-a-uuid');
    }
}
