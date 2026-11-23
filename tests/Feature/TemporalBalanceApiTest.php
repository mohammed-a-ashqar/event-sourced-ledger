<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class TemporalBalanceApiTest extends TestCase
{
    #[Test]
    public function the_balance_can_be_asked_for_any_past_moment(): void
    {
        $this->clock->setTo('2026-03-01 09:00:00');
        $id = self::str($this->postJson('/api/v1/accounts', ['owner_name' => 'Alice', 'currency' => 'EUR'])->json('data.id'));
        $this->clock->setTo('2026-03-02 10:00:00');
        $this->postJson("/api/v1/accounts/{$id}/deposits", ['amount' => 5_000, 'currency' => 'EUR'])->assertCreated();
        $this->clock->setTo('2026-03-03 10:00:00');
        $this->postJson("/api/v1/accounts/{$id}/withdrawals", ['amount' => 1_200, 'currency' => 'EUR'])->assertCreated();

        $this->getJson("/api/v1/accounts/{$id}/balance?as_of=2026-03-02T12:00:00Z")
            ->assertOk()
            ->assertJsonPath('data.existed', true)
            ->assertJsonPath('data.as_of', '2026-03-02T12:00:00.000000+00:00')
            ->assertJsonPath('data.balance.amount', 5_000);

        $this->getJson("/api/v1/accounts/{$id}/balance?as_of=".urlencode('2026-03-03T12:59:59+03:00'))
            ->assertJsonPath('data.balance.amount', 5_000);

        $this->getJson("/api/v1/accounts/{$id}/balance?as_of=2026-03-03T10:00:00Z")
            ->assertJsonPath('data.balance.amount', 3_800);

        $this->getJson("/api/v1/accounts/{$id}/balance")
            ->assertJsonPath('data.as_of', null)
            ->assertJsonPath('data.balance.amount', 3_800);
    }

    #[Test]
    public function before_the_account_was_opened_it_did_not_exist(): void
    {
        $id = self::str($this->postJson('/api/v1/accounts', ['owner_name' => 'Alice', 'currency' => 'EUR'])->json('data.id'));

        $this->getJson("/api/v1/accounts/{$id}/balance?as_of=2020-01-01T00:00:00Z")
            ->assertOk()
            ->assertJsonPath('data.existed', false)
            ->assertJsonPath('data.balance', null);
    }

    #[Test]
    public function an_invalid_moment_is_a_validation_error(): void
    {
        $id = self::str($this->postJson('/api/v1/accounts', ['owner_name' => 'Alice', 'currency' => 'EUR'])->json('data.id'));

        $this->getJson("/api/v1/accounts/{$id}/balance?as_of=yesterday-ish")
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed');
    }

    #[Test]
    public function temporal_queries_on_unknown_accounts_are_404(): void
    {
        $this->getJson('/api/v1/accounts/6f1c2b3a-4d5e-4f60-8a7b-9c0d1e2f3a4b/balance?as_of=2026-01-01T00:00:00Z')
            ->assertNotFound()
            ->assertJsonPath('error.code', 'account_not_found');
    }
}
