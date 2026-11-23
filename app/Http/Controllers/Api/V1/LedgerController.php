<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Ledger\Infrastructure\ReadModel\TrialBalance;

final readonly class LedgerController
{
    public function trialBalance(TrialBalance $trialBalance): JsonResponse
    {
        return new JsonResponse(['data' => $trialBalance->compute()]);
    }
}
