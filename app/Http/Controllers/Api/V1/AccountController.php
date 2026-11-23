<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\BalanceRequest;
use App\Http\Requests\FreezeAccountRequest;
use App\Http\Requests\MoneyMovementRequest;
use App\Http\Requests\OpenAccountRequest;
use App\Http\Requests\StatementRequest;
use Illuminate\Http\JsonResponse;
use Ledger\Application\Account\DepositMoney;
use Ledger\Application\Account\FreezeAccount;
use Ledger\Application\Account\UnfreezeAccount;
use Ledger\Application\Account\WithdrawMoney;
use Ledger\Application\Bus\CommandBus;
use Ledger\Application\Query\BalanceAsOf;
use Ledger\Domain\Account\AccountId;
use Ledger\Domain\Account\Exception\AccountNotFound;
use Ledger\Domain\Shared\TransactionId;
use Ledger\EventSourcing\Metadata;
use Ledger\Infrastructure\ReadModel\AccountReadModel;
use Ledger\Infrastructure\ReadModel\MoneyView;

/**
 * Commands go through the bus; responses are read back from the read models
 * (projected synchronously, see config/ledger.php).
 */
final readonly class AccountController
{
    public function __construct(
        private CommandBus $bus,
        private AccountReadModel $accounts,
    ) {}

    public function open(OpenAccountRequest $request): JsonResponse
    {
        $accountId = AccountId::generate();
        $this->bus->dispatch($request->toCommand($accountId));

        return new JsonResponse(
            ['data' => $this->account($accountId)],
            201,
            ['Location' => route('accounts.show', ['accountId' => $accountId->value])],
        );
    }

    public function show(string $accountId): JsonResponse
    {
        return new JsonResponse(['data' => $this->account(AccountId::fromString($accountId))]);
    }

    public function deposit(MoneyMovementRequest $request, string $accountId): JsonResponse
    {
        $id = AccountId::fromString($accountId);
        $transactionId = TransactionId::generate();

        $this->bus->dispatch(new DepositMoney($id, $transactionId, $request->amount(), $request->currency(), $request->reference()));

        return $this->movement($transactionId, $id);
    }

    public function withdraw(MoneyMovementRequest $request, string $accountId): JsonResponse
    {
        $id = AccountId::fromString($accountId);
        $transactionId = TransactionId::generate();

        $this->bus->dispatch(new WithdrawMoney($id, $transactionId, $request->amount(), $request->currency(), $request->reference()));

        return $this->movement($transactionId, $id);
    }

    public function freeze(FreezeAccountRequest $request, string $accountId): JsonResponse
    {
        $id = AccountId::fromString($accountId);
        $this->bus->dispatch(new FreezeAccount($id, $request->reason()));

        return new JsonResponse(['data' => $this->account($id)]);
    }

    public function unfreeze(string $accountId): JsonResponse
    {
        $id = AccountId::fromString($accountId);
        $this->bus->dispatch(new UnfreezeAccount($id));

        return new JsonResponse(['data' => $this->account($id)]);
    }

    /**
     * Without as_of: the projected balance. With as_of: rebuilt by replaying
     * the account's events up to that moment.
     */
    public function balance(BalanceRequest $request, string $accountId, BalanceAsOf $balanceAsOf): JsonResponse
    {
        $id = AccountId::fromString($accountId);
        $asOf = $request->asOf();

        if ($asOf === null) {
            $account = $this->account($id);

            return new JsonResponse(['data' => ['account_id' => $id->value, 'as_of' => null, 'balance' => $account['balance']]]);
        }

        $balance = $balanceAsOf($id, $asOf);

        return new JsonResponse(['data' => [
            'account_id' => $id->value,
            'as_of' => $asOf->format(Metadata::DATE_FORMAT),
            'existed' => $balance !== null,
            'balance' => $balance === null ? null : MoneyView::of($balance->amount, $balance->currency->code),
        ]]);
    }

    public function statement(StatementRequest $request, string $accountId): JsonResponse
    {
        $id = AccountId::fromString($accountId);
        $this->account($id);

        $lines = $this->accounts->statement($id->value, $request->afterPosition(), $request->limit());
        $last = end($lines);

        return new JsonResponse([
            'data' => $lines,
            'meta' => ['next_after' => count($lines) === $request->limit() && $last !== false ? $last['position'] : null],
        ]);
    }

    private function movement(TransactionId $transactionId, AccountId $accountId): JsonResponse
    {
        return new JsonResponse(['data' => [
            'transaction_id' => $transactionId->value,
            'account' => $this->account($accountId),
        ]], 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function account(AccountId $id): array
    {
        return $this->accounts->find($id->value) ?? throw AccountNotFound::withId($id);
    }
}
