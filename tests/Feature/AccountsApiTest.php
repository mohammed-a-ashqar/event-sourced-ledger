<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class AccountsApiTest extends TestCase
{
    private function openAccount(string $owner = 'Alice', string $currency = 'EUR', int $overdraft = 0): string
    {
        $id = $this->postJson('/api/v1/accounts', [
            'owner_name' => $owner,
            'currency' => $currency,
            'overdraft_limit' => $overdraft,
        ])->assertCreated()->json('data.id');

        self::assertIsString($id);

        return $id;
    }

    #[Test]
    public function opening_an_account_returns_its_summary_and_location(): void
    {
        $response = $this->postJson('/api/v1/accounts', ['owner_name' => 'Alice', 'currency' => 'EUR', 'overdraft_limit' => 5_000]);

        $id = $response->json('data.id');
        $response->assertCreated()
            ->assertHeader('Location', url('/api/v1/accounts/'.$id))
            ->assertJsonPath('data.owner_name', 'Alice')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.balance', ['amount' => 0, 'currency' => 'EUR', 'formatted' => '0.00'])
            ->assertJsonPath('data.available.amount', 5_000)
            ->assertJsonPath('data.version', 1);
    }

    #[Test]
    public function an_account_can_be_fetched_by_id(): void
    {
        $id = $this->openAccount();

        $this->getJson('/api/v1/accounts/'.$id)
            ->assertOk()
            ->assertJsonPath('data.id', $id)
            ->assertJsonStructure(['data' => ['id', 'owner_name', 'status', 'balance', 'overdraft_limit', 'available', 'version', 'opened_at', 'updated_at']]);
    }

    #[Test]
    public function deposits_and_withdrawals_update_the_balance(): void
    {
        $id = $this->openAccount();

        $this->postJson("/api/v1/accounts/{$id}/deposits", ['amount' => 10_000, 'currency' => 'EUR', 'reference' => 'salary'])
            ->assertCreated()
            ->assertJsonPath('data.account.balance.amount', 10_000)
            ->assertJsonStructure(['data' => ['transaction_id', 'account']]);

        $this->postJson("/api/v1/accounts/{$id}/withdrawals", ['amount' => 2_550, 'currency' => 'EUR'])
            ->assertCreated()
            ->assertJsonPath('data.account.balance.formatted', '74.50');
    }

    #[Test]
    public function overdrawing_is_refused_with_a_domain_error(): void
    {
        $id = $this->openAccount(overdraft: 1_000);

        $this->postJson("/api/v1/accounts/{$id}/withdrawals", ['amount' => 1_001, 'currency' => 'EUR'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'insufficient_funds');

        $this->postJson("/api/v1/accounts/{$id}/withdrawals", ['amount' => 1_000, 'currency' => 'EUR'])
            ->assertCreated()
            ->assertJsonPath('data.account.balance.amount', -1_000);
    }

    #[Test]
    public function a_deposit_in_the_wrong_currency_is_refused(): void
    {
        $id = $this->openAccount(currency: 'USD');

        $this->postJson("/api/v1/accounts/{$id}/deposits", ['amount' => 100, 'currency' => 'EUR'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'currency_mismatch');
    }

    #[Test]
    public function a_frozen_account_rejects_movements_until_unfrozen(): void
    {
        $id = $this->openAccount();

        $this->postJson("/api/v1/accounts/{$id}/freeze", ['reason' => 'KYC review'])
            ->assertOk()
            ->assertJsonPath('data.status', 'frozen');

        $this->postJson("/api/v1/accounts/{$id}/deposits", ['amount' => 100, 'currency' => 'EUR'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'account_frozen');

        $this->postJson("/api/v1/accounts/{$id}/unfreeze")
            ->assertOk()
            ->assertJsonPath('data.status', 'active');

        $this->postJson("/api/v1/accounts/{$id}/deposits", ['amount' => 100, 'currency' => 'EUR'])->assertCreated();
    }

    #[Test]
    public function unfreezing_an_active_account_is_a_conflict(): void
    {
        $id = $this->openAccount();

        $this->postJson("/api/v1/accounts/{$id}/unfreeze")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'account_not_frozen');
    }

    #[Test]
    public function the_statement_lists_movements_with_running_balances_and_pages(): void
    {
        $id = $this->openAccount();
        foreach ([1_000, 2_000, 3_000] as $amount) {
            $this->postJson("/api/v1/accounts/{$id}/deposits", ['amount' => $amount, 'currency' => 'EUR'])->assertCreated();
        }
        $this->postJson("/api/v1/accounts/{$id}/withdrawals", ['amount' => 500, 'currency' => 'EUR'])->assertCreated();

        $first = $this->getJson("/api/v1/accounts/{$id}/statement?limit=3")->assertOk();
        $first->assertJsonCount(3, 'data')
            ->assertJsonPath('data.2.balance_after.amount', 6_000);

        $next = $first->json('meta.next_after');
        self::assertIsInt($next);

        $this->getJson("/api/v1/accounts/{$id}/statement?limit=3&after={$next}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.type', 'withdrawal')
            ->assertJsonPath('data.0.amount.amount', -500)
            ->assertJsonPath('data.0.balance_after.amount', 5_500)
            ->assertJsonPath('meta.next_after', null);
    }

    #[Test]
    public function unknown_accounts_are_404_with_the_error_format(): void
    {
        $this->getJson('/api/v1/accounts/9b2f0a0e-1d3c-4c4e-9f7a-2b1d0c9e8f7a')
            ->assertNotFound()
            ->assertExactJson(['error' => [
                'code' => 'account_not_found',
                'message' => 'Account 9b2f0a0e-1d3c-4c4e-9f7a-2b1d0c9e8f7a does not exist.',
            ]]);
    }

    #[Test]
    public function malformed_ids_do_not_reach_the_controller(): void
    {
        $this->getJson('/api/v1/accounts/not-a-uuid')
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found');
    }

    #[Test]
    public function every_write_carries_the_request_correlation_id(): void
    {
        $this->withHeader('X-Correlation-ID', 'checkout-7f3a')
            ->postJson('/api/v1/accounts', ['owner_name' => 'Alice', 'currency' => 'EUR'])
            ->assertCreated()
            ->assertHeader('X-Correlation-ID', 'checkout-7f3a');

        $metadata = json_decode((string) DB::table('stored_events')->value('metadata'), true);

        self::assertIsArray($metadata);
        self::assertSame('checkout-7f3a', $metadata['correlation_id']);
    }

    #[Test]
    public function a_correlation_id_is_generated_when_missing_or_invalid(): void
    {
        $response = $this->withHeader('X-Correlation-ID', "bad id\n")->getJson('/api/v1/ledger/trial-balance');

        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', (string) $response->headers->get('X-Correlation-ID'));
    }
}
