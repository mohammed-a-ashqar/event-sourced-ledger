<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\TransferMoneyRequest;
use Illuminate\Http\JsonResponse;
use Ledger\Application\Bus\CommandBus;
use Ledger\Domain\Transfer\Exception\TransferNotFound;
use Ledger\Domain\Transfer\TransferId;
use Ledger\Infrastructure\ReadModel\TransferReadModel;

final readonly class TransferController
{
    public function __construct(
        private CommandBus $bus,
        private TransferReadModel $transfers,
    ) {}

    /**
     * Responds 201 for every transfer that was recorded, whatever its
     * outcome: "rejected" and "compensated" are legitimate final states of
     * the saga, reported in the status field.
     */
    public function store(TransferMoneyRequest $request): JsonResponse
    {
        $transferId = TransferId::generate();
        $this->bus->dispatch($request->toCommand($transferId));

        return new JsonResponse(
            ['data' => $this->transfer($transferId)],
            201,
            ['Location' => route('transfers.show', ['transferId' => $transferId->value])],
        );
    }

    public function show(string $transferId): JsonResponse
    {
        return new JsonResponse(['data' => $this->transfer(TransferId::fromString($transferId))]);
    }

    /**
     * @return array<string, mixed>
     */
    private function transfer(TransferId $id): array
    {
        return $this->transfers->find($id->value) ?? throw TransferNotFound::withId($id);
    }
}
