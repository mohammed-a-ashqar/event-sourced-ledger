<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Shared;

use Ledger\Domain\Shared\Currency;
use Ledger\Domain\Shared\CurrencyMismatch;
use Ledger\Domain\Shared\InvalidAmount;
use Ledger\Domain\Shared\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    #[Test]
    public function it_adds_and_subtracts_in_minor_units(): void
    {
        $sum = Money::of(1050, 'USD')->add(Money::of(275, 'USD'));

        self::assertSame(1325, $sum->amount);
        self::assertSame(-400, Money::of(100, 'USD')->subtract(Money::of(500, 'USD'))->amount);
    }

    #[Test]
    public function it_refuses_to_mix_currencies(): void
    {
        $this->expectException(CurrencyMismatch::class);

        Money::of(100, 'USD')->add(Money::of(100, 'EUR'));
    }

    #[Test]
    public function comparisons_also_require_the_same_currency(): void
    {
        $this->expectException(CurrencyMismatch::class);

        Money::of(100, 'USD')->isLessThan(Money::of(100, 'GBP'));
    }

    #[Test]
    public function it_detects_integer_overflow_instead_of_silently_becoming_a_float(): void
    {
        $this->expectException(InvalidAmount::class);

        Money::of(PHP_INT_MAX, 'USD')->add(Money::of(1, 'USD'));
    }

    #[Test]
    public function it_detects_negative_overflow(): void
    {
        $this->expectException(InvalidAmount::class);

        Money::of(PHP_INT_MIN, 'USD')->negate();
    }

    /**
     * @return iterable<string, array{string, string, int}>
     */
    public static function decimals(): iterable
    {
        yield 'two decimals' => ['12.34', 'USD', 1234];
        yield 'one decimal is padded' => ['12.5', 'EUR', 1250];
        yield 'no decimals' => ['7', 'GBP', 700];
        yield 'negative' => ['-0.05', 'USD', -5];
        yield 'zero-decimal currency' => ['500', 'JPY', 500];
        yield 'three-decimal currency' => ['1.234', 'KWD', 1234];
        yield 'leading zeros' => ['0007.10', 'USD', 710];
        yield 'zero' => ['0.00', 'USD', 0];
    }

    #[Test]
    #[DataProvider('decimals')]
    public function it_parses_decimal_strings_without_floats(string $decimal, string $currency, int $expectedMinor): void
    {
        self::assertSame($expectedMinor, Money::fromDecimal($decimal, Currency::of($currency))->amount);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidDecimals(): iterable
    {
        yield 'too many decimals' => ['1.234', 'USD'];
        yield 'decimals on a zero-decimal currency' => ['1.5', 'JPY'];
        yield 'float notation' => ['1e3', 'USD'];
        yield 'empty' => ['', 'USD'];
        yield 'thousand separator' => ['1,000.00', 'USD'];
        yield 'beyond 64-bit range' => ['99999999999999999999', 'USD'];
    }

    #[Test]
    #[DataProvider('invalidDecimals')]
    public function it_rejects_malformed_decimals(string $decimal, string $currency): void
    {
        $this->expectException(InvalidAmount::class);

        Money::fromDecimal($decimal, Currency::of($currency));
    }

    /**
     * @return iterable<string, array{int, string, string}>
     */
    public static function formatted(): iterable
    {
        yield 'cents' => [1234, 'USD', '12.34'];
        yield 'sub-unit' => [5, 'USD', '0.05'];
        yield 'negative' => [-1250, 'EUR', '-12.50'];
        yield 'yen' => [500, 'JPY', '500'];
        yield 'dinar' => [1, 'KWD', '0.001'];
        yield 'smallest integer' => [PHP_INT_MIN, 'JPY', (string) PHP_INT_MIN];
    }

    #[Test]
    #[DataProvider('formatted')]
    public function it_formats_as_a_decimal_string(int $minor, string $currency, string $expected): void
    {
        self::assertSame($expected, Money::of($minor, $currency)->toDecimal());
    }

    #[Test]
    public function equality_considers_amount_and_currency(): void
    {
        self::assertTrue(Money::of(100, 'USD')->equals(Money::of(100, 'USD')));
        self::assertFalse(Money::of(100, 'USD')->equals(Money::of(100, 'EUR')));
        self::assertFalse(Money::of(100, 'USD')->equals(Money::of(101, 'USD')));
    }

    #[Test]
    public function sign_predicates(): void
    {
        self::assertTrue(Money::of(1, 'USD')->isPositive());
        self::assertTrue(Money::of(-1, 'USD')->isNegative());
        self::assertTrue(Money::zero(Currency::of('USD'))->isZero());
        self::assertFalse(Money::zero(Currency::of('USD'))->isPositive());
    }
}
