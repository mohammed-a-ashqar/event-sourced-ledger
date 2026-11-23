<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ValidationAndErrorFormatTest extends TestCase
{
    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidAccounts(): iterable
    {
        yield 'missing owner' => [['currency' => 'EUR'], 'owner_name'];
        yield 'unsupported currency' => [['owner_name' => 'A', 'currency' => 'XYZ'], 'currency'];
        yield 'lowercase currency' => [['owner_name' => 'A', 'currency' => 'eur'], 'currency'];
        yield 'negative overdraft' => [['owner_name' => 'A', 'currency' => 'EUR', 'overdraft_limit' => -1], 'overdraft_limit'];
        yield 'fractional overdraft' => [['owner_name' => 'A', 'currency' => 'EUR', 'overdraft_limit' => 10.5], 'overdraft_limit'];
        yield 'owner too long' => [['owner_name' => str_repeat('a', 121), 'currency' => 'EUR'], 'owner_name'];
    }

    #[Test]
    #[DataProvider('invalidAccounts')]
    public function invalid_account_requests_are_rejected_with_field_errors(array $body, string $field): void
    {
        $this->postJson('/api/v1/accounts', $body)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.message', 'The request is invalid.')
            ->assertJsonValidationErrors([$field], 'error.details');
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidAmounts(): iterable
    {
        yield 'zero' => [['amount' => 0, 'currency' => 'EUR'], 'amount'];
        yield 'negative' => [['amount' => -5, 'currency' => 'EUR'], 'amount'];
        yield 'float' => [['amount' => 12.5, 'currency' => 'EUR'], 'amount'];
        yield 'decimal string' => [['amount' => '12.50', 'currency' => 'EUR'], 'amount'];
        yield 'above the ceiling' => [['amount' => 1_000_000_000_000_001, 'currency' => 'EUR'], 'amount'];
        yield 'missing currency' => [['amount' => 100], 'currency'];
    }

    #[Test]
    #[DataProvider('invalidAmounts')]
    public function amounts_must_be_positive_integers_in_minor_units(array $body, string $field): void
    {
        $id = $this->postJson('/api/v1/accounts', ['owner_name' => 'A', 'currency' => 'EUR'])->json('data.id');

        $this->postJson("/api/v1/accounts/{$id}/deposits", $body)
            ->assertStatus(422)
            ->assertJsonValidationErrors([$field], 'error.details');

        $this->getJson("/api/v1/accounts/{$id}")->assertJsonPath('data.version', 1);
    }

    #[Test]
    public function unknown_routes_and_methods_use_the_error_format(): void
    {
        $this->getJson('/api/v1/nope')->assertNotFound()->assertJsonPath('error.code', 'not_found');

        $this->deleteJson('/api/v1/ledger/trial-balance')
            ->assertStatus(405)
            ->assertJsonPath('error.code', 'method_not_allowed');
    }

    #[Test]
    public function statement_paging_parameters_are_validated(): void
    {
        $id = $this->postJson('/api/v1/accounts', ['owner_name' => 'A', 'currency' => 'EUR'])->json('data.id');

        $this->getJson("/api/v1/accounts/{$id}/statement?limit=500")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['limit'], 'error.details');
    }
}
