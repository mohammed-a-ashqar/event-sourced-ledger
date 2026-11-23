<?php

declare(strict_types=1);

namespace Tests\Integration;

use Illuminate\Support\Facades\DB;
use Ledger\Application\Account\DepositMoney;
use Ledger\Application\Account\OpenAccount;
use Ledger\Application\Messaging\IntegrationMessage;
use Ledger\Application\Transfer\TransferMoney;
use Ledger\Domain\Account\AccountRepository;
use Ledger\Domain\Shared\TransactionId;
use Ledger\EventSourcing\ConcurrencyException;
use Ledger\EventSourcing\EventStore;
use Ledger\Infrastructure\Outbox\OutboxRelay;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Fixtures as F;
use Tests\TestCase;

final class OutboxTest extends TestCase
{
    private function openAndFund(): void
    {
        $this->bus()->dispatch(new OpenAccount(F::alice(), 'Alice', 'EUR', 0));
        $this->bus()->dispatch(new OpenAccount(F::bob(), 'Bob', 'EUR', 0));
        $this->bus()->dispatch(new DepositMoney(F::alice(), F::transactionId(), 1_000, 'EUR'));
    }

    /**
     * @return list<string>
     */
    private function publishedTypes(): array
    {
        return array_map(fn (IntegrationMessage $m) => $m->type, $this->publisher->published());
    }

    #[Test]
    public function integration_messages_are_written_with_the_events_and_only_for_outcomes(): void
    {
        $this->openAndFund();
        $this->bus()->dispatch(new TransferMoney(F::transferId(), F::alice(), F::bob(), 400, 'EUR'));

        self::assertSame([
            'ledger.account.opened.v1',
            'ledger.account.opened.v1',
            'ledger.funds.deposited.v1',
            'ledger.transfer.completed.v1',
        ], DB::table('outbox_messages')->orderBy('id')->pluck('message_type')->all());

        self::assertSame([], $this->publisher->published(), 'Nothing is sent before the relay runs.');
    }

    #[Test]
    public function the_message_id_is_the_id_of_the_originating_event(): void
    {
        $this->openAndFund();

        $deposit = array_values(array_filter(
            $this->app->make(EventStore::class)->load('account-'.F::ALICE),
            fn ($e) => $e->version === 2,
        ))[0];

        $message = DB::table('outbox_messages')->where('message_type', 'ledger.funds.deposited.v1')->first();

        self::assertNotNull($message);
        self::assertSame($deposit->eventId(), $message->message_id);
        self::assertEquals(
            ['account_id' => F::ALICE, 'transaction_id' => F::TRANSACTION, 'amount' => 1_000, 'currency' => 'EUR'],
            json_decode(self::str($message->payload), true),
        );
    }

    #[Test]
    public function a_rejected_append_leaves_no_message_behind(): void
    {
        $this->openAndFund();
        $before = DB::table('outbox_messages')->count();
        $repository = $this->app->make(AccountRepository::class);

        $stale = $repository->get(F::alice());
        $fresh = $repository->get(F::alice());
        $fresh->deposit(TransactionId::generate(), F::eur(1));
        $repository->save($fresh);

        $stale->deposit(TransactionId::generate(), F::eur(2));

        try {
            $repository->save($stale);
            self::fail('Expected a concurrency conflict.');
        } catch (ConcurrencyException) {
            self::assertSame($before + 1, DB::table('outbox_messages')->count());
        }
    }

    #[Test]
    public function the_relay_publishes_in_order_and_marks_messages_as_sent(): void
    {
        $this->openAndFund();

        $this->command('ledger:outbox:relay')
            ->expectsOutput('Published 3 message(s), 0 failed.')
            ->assertSuccessful();

        self::assertSame(['ledger.account.opened.v1', 'ledger.account.opened.v1', 'ledger.funds.deposited.v1'], $this->publishedTypes());
        self::assertSame(0, DB::table('outbox_messages')->whereNull('published_at')->count());
        self::assertArrayHasKey('correlation_id', $this->publisher->published()[0]->headers);

        $this->command('ledger:outbox:relay')->expectsOutput('Published 0 message(s), 0 failed.');
        self::assertCount(3, $this->publisher->published(), 'Published messages are not sent again.');
    }

    #[Test]
    public function the_relay_respects_its_limit(): void
    {
        $this->openAndFund();

        $report = $this->app->make(OutboxRelay::class)->relay(2);

        self::assertSame(2, $report->published);
        self::assertSame(1, DB::table('outbox_messages')->whereNull('published_at')->count());
    }

    #[Test]
    public function a_failing_message_is_retried_later_without_blocking_the_others(): void
    {
        $this->openAndFund();
        $this->publisher->failFor('ledger.account.opened.v1');

        $this->command('ledger:outbox:relay')
            ->expectsOutput('Published 1 message(s), 2 failed.')
            ->assertFailed();

        $failed = DB::table('outbox_messages')->where('message_type', 'ledger.account.opened.v1')->orderBy('id')->get();
        self::assertSame([1, 1], $failed->pluck('attempts')->map(fn (mixed $a) => self::int($a))->all());
        self::assertStringContainsString('Broker rejected', self::str($failed->first()?->last_error));

        $this->publisher->recover();
        $this->command('ledger:outbox:relay')->expectsOutput('Published 2 message(s), 0 failed.');

        self::assertSame(0, DB::table('outbox_messages')->whereNull('published_at')->count());
        self::assertNull(DB::table('outbox_messages')->value('last_error'));
    }

    #[Test]
    public function a_poison_message_is_parked_after_the_maximum_attempts(): void
    {
        config(['ledger.outbox.max_attempts' => 2]);
        $this->app->forgetInstance(OutboxRelay::class);
        $this->openAndFund();
        $this->publisher->failFor('ledger.funds.deposited.v1');

        $relay = $this->app->make(OutboxRelay::class);
        $relay->relay(10);
        $relay->relay(10);
        $third = $relay->relay(10);

        self::assertSame(0, $third->published + $third->failed, 'The parked message is no longer picked up.');
        self::assertSame(2, self::int(DB::table('outbox_messages')->where('message_type', 'ledger.funds.deposited.v1')->value('attempts')));
    }

    #[Test]
    public function the_limit_option_is_validated(): void
    {
        $this->command('ledger:outbox:relay', ['--limit' => 'abc'])
            ->expectsOutput('--limit must be a positive integer.')
            ->assertExitCode(2);
    }
}
