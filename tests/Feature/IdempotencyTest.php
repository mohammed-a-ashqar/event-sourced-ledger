<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

final class IdempotencyTest extends TestCase
{
    private string $account;

    protected function setUp(): void
    {
        parent::setUp();

        $id = self::str($this->postJson('/api/v1/accounts', ['owner_name' => 'Alice', 'currency' => 'EUR'])->json('data.id'));
        $this->account = $id;
    }

    /**
     * @param  array<string, mixed>  $body
     * @return TestResponse<Response>
     */
    private function postWithKey(string $uri, array $body, ?string $key = null): TestResponse
    {
        return $this->postJson($uri, $body, $key === null ? [] : ['Idempotency-Key' => $key]);
    }

    /**
     * @return TestResponse<Response>
     */
    private function deposit(?string $key, int $amount = 1_000): TestResponse
    {
        return $this->postWithKey("/api/v1/accounts/{$this->account}/deposits", ['amount' => $amount, 'currency' => 'EUR'], $key);
    }

    private function balance(): mixed
    {
        return $this->getJson("/api/v1/accounts/{$this->account}")->json('data.balance.amount');
    }

    #[Test]
    public function the_same_key_and_body_replays_the_first_response_without_executing_again(): void
    {
        $first = $this->deposit('deposit-001')->assertCreated();
        $second = $this->deposit('deposit-001')->assertCreated();

        self::assertSame($first->getContent(), $second->getContent());
        $second->assertHeader('Idempotent-Replayed', 'true');
        $first->assertHeaderMissing('Idempotent-Replayed');

        self::assertSame(1_000, $this->balance());
        self::assertSame(1, DB::table('stored_events')->where('event_type', 'account.money_deposited')->count());
    }

    #[Test]
    public function reusing_a_key_for_a_different_body_is_a_conflict(): void
    {
        $this->deposit('deposit-002', 1_000)->assertCreated();

        $this->deposit('deposit-002', 2_000)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'idempotency_key_reused');

        self::assertSame(1_000, $this->balance());
    }

    #[Test]
    public function reusing_a_key_on_another_endpoint_is_a_conflict(): void
    {
        $this->deposit('shared-key')->assertCreated();

        $this->postWithKey("/api/v1/accounts/{$this->account}/withdrawals", ['amount' => 1_000, 'currency' => 'EUR'], 'shared-key')
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'idempotency_key_reused');
    }

    #[Test]
    public function json_key_order_does_not_change_the_fingerprint(): void
    {
        $this->postWithKey("/api/v1/accounts/{$this->account}/deposits", ['amount' => 500, 'currency' => 'EUR'], 'k-order')->assertCreated();

        $this->postWithKey("/api/v1/accounts/{$this->account}/deposits", ['currency' => 'EUR', 'amount' => 500], 'k-order')
            ->assertCreated()
            ->assertHeader('Idempotent-Replayed', 'true');
    }

    #[Test]
    public function business_errors_are_part_of_the_remembered_outcome(): void
    {
        $withdraw = fn () => $this->postWithKey("/api/v1/accounts/{$this->account}/withdrawals", ['amount' => 5_000, 'currency' => 'EUR'], 'withdraw-too-much');

        $withdraw()->assertStatus(422)->assertJsonPath('error.code', 'insufficient_funds');

        // Funds arrive in between, but the retried request must get the same answer.
        $this->deposit(null, 10_000)->assertCreated();

        $withdraw()->assertStatus(422)->assertHeader('Idempotent-Replayed', 'true');
        self::assertSame(10_000, $this->balance());
    }

    #[Test]
    public function a_request_still_in_progress_is_not_executed_twice(): void
    {
        DB::table('idempotency_keys')->insert([
            'idempotency_key' => 'in-flight',
            'fingerprint' => hash('sha256', 'POST api/v1/accounts/'.$this->account."/deposits\n".json_encode(['amount' => 1_000, 'currency' => 'EUR'])),
            'created_at' => '2026-03-01 09:00:00.000000',
        ]);

        $this->deposit('in-flight')
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'idempotency_request_in_progress');

        self::assertSame(0, $this->balance());
    }

    #[Test]
    public function an_expired_key_can_be_used_again(): void
    {
        $this->deposit('old-key')->assertCreated();
        $this->clock->advance('+25 hours');

        $this->deposit('old-key', 2_000)->assertCreated()->assertHeaderMissing('Idempotent-Replayed');

        self::assertSame(3_000, $this->balance());
    }

    #[Test]
    public function transfers_are_idempotent_too_and_keep_their_location(): void
    {
        $bob = self::str($this->postJson('/api/v1/accounts', ['owner_name' => 'Bob', 'currency' => 'EUR'])->json('data.id'));
        $this->deposit('fund', 5_000)->assertCreated();
        $body = ['source_account_id' => $this->account, 'destination_account_id' => $bob, 'amount' => 1_500, 'currency' => 'EUR'];

        $first = $this->postWithKey('/api/v1/transfers', $body, 'transfer-1')->assertCreated();
        $second = $this->postWithKey('/api/v1/transfers', $body, 'transfer-1')->assertCreated();

        self::assertSame($first->json('data.id'), $second->json('data.id'));
        $second->assertHeader('Location', (string) $first->headers->get('Location'));
        self::assertSame(1, DB::table('transfers')->count());
        self::assertSame(3_500, $this->balance());
    }

    #[Test]
    public function a_malformed_key_is_rejected(): void
    {
        $this->deposit(str_repeat('k', 256))
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'invalid_idempotency_key');
    }

    #[Test]
    public function requests_without_a_key_are_executed_every_time(): void
    {
        $this->deposit(null, 100)->assertCreated();
        $this->deposit(null, 100)->assertCreated();

        self::assertSame(200, $this->balance());
    }
}
