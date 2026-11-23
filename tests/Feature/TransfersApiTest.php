<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class TransfersApiTest extends TestCase
{
    private function account(string $currency = 'EUR', int $deposit = 0): string
    {
        $id = self::str($this->postJson('/api/v1/accounts', ['owner_name' => 'Owner', 'currency' => $currency])->json('data.id'));

        if ($deposit > 0) {
            $this->postJson("/api/v1/accounts/{$id}/deposits", ['amount' => $deposit, 'currency' => $currency])->assertCreated();
        }

        return $id;
    }

    /**
     * @return array<string, mixed>
     */
    private function transfer(string $from, string $to, int $amount, string $currency = 'EUR'): array
    {
        return ['source_account_id' => $from, 'destination_account_id' => $to, 'amount' => $amount, 'currency' => $currency];
    }

    #[Test]
    public function a_transfer_moves_money_between_accounts(): void
    {
        $alice = $this->account(deposit: 10_000);
        $bob = $this->account();

        $response = $this->postJson('/api/v1/transfers', $this->transfer($alice, $bob, 2_500))
            ->assertCreated()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.amount.formatted', '25.00')
            ->assertJsonPath('data.failure_reason', null);

        $this->getJson('/api/v1/transfers/'.self::str($response->json('data.id')))->assertOk()->assertJsonPath('data.status', 'completed');
        $this->getJson("/api/v1/accounts/{$alice}")->assertJsonPath('data.balance.amount', 7_500);
        $this->getJson("/api/v1/accounts/{$bob}")->assertJsonPath('data.balance.amount', 2_500);
    }

    #[Test]
    public function a_transfer_without_funds_is_recorded_as_rejected(): void
    {
        $alice = $this->account(deposit: 100);
        $bob = $this->account();

        $this->postJson('/api/v1/transfers', $this->transfer($alice, $bob, 101))
            ->assertCreated()
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.failure_reason', fn (string $reason) => str_contains($reason, 'cannot be debited'));

        $this->getJson("/api/v1/accounts/{$alice}")->assertJsonPath('data.balance.amount', 100);
    }

    #[Test]
    public function a_transfer_to_a_frozen_account_is_compensated(): void
    {
        $alice = $this->account(deposit: 1_000);
        $bob = $this->account();
        $this->postJson("/api/v1/accounts/{$bob}/freeze", ['reason' => 'sanctions screening'])->assertOk();

        $this->postJson('/api/v1/transfers', $this->transfer($alice, $bob, 400))
            ->assertCreated()
            ->assertJsonPath('data.status', 'compensated');

        $this->getJson("/api/v1/accounts/{$alice}")->assertJsonPath('data.balance.amount', 1_000);
        $this->getJson("/api/v1/accounts/{$alice}/statement")
            ->assertJsonPath('data.1.type', 'transfer_out')
            ->assertJsonPath('data.2.type', 'transfer_reversal')
            ->assertJsonPath('data.2.balance_after.amount', 1_000);
    }

    #[Test]
    public function the_trial_balance_stays_balanced_through_every_outcome(): void
    {
        $alice = $this->account(deposit: 1_000);
        $bob = $this->account();
        $carol = $this->account();
        $this->postJson("/api/v1/accounts/{$carol}/freeze", ['reason' => 'hold'])->assertOk();

        $this->postJson('/api/v1/transfers', $this->transfer($alice, $bob, 300))->assertJsonPath('data.status', 'completed');
        $this->postJson('/api/v1/transfers', $this->transfer($alice, $carol, 200))->assertJsonPath('data.status', 'compensated');
        $this->postJson('/api/v1/transfers', $this->transfer($alice, $bob, 5_000))->assertJsonPath('data.status', 'rejected');

        $this->getJson('/api/v1/ledger/trial-balance')
            ->assertOk()
            ->assertJsonPath('data.currencies.0.balanced', true)
            ->assertJsonPath('data.currencies.0.debits', 1_000 + 300 + 300 + 200 + 200);
    }

    #[Test]
    public function both_accounts_must_exist(): void
    {
        $alice = $this->account(deposit: 1_000);

        $this->postJson('/api/v1/transfers', $this->transfer($alice, '6f1c2b3a-4d5e-4f60-8a7b-9c0d1e2f3a4b', 100))
            ->assertNotFound()
            ->assertJsonPath('error.code', 'account_not_found');
    }

    #[Test]
    public function a_transfer_to_the_same_account_fails_validation(): void
    {
        $alice = $this->account(deposit: 1_000);

        $this->postJson('/api/v1/transfers', $this->transfer($alice, $alice, 100))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonValidationErrors(['destination_account_id'], 'error.details');
    }

    #[Test]
    public function unknown_transfers_are_404(): void
    {
        $this->getJson('/api/v1/transfers/6f1c2b3a-4d5e-4f60-8a7b-9c0d1e2f3a4b')
            ->assertNotFound()
            ->assertJsonPath('error.code', 'transfer_not_found');
    }
}
