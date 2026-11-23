<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\LedgerController;
use App\Http\Controllers\Api\V1\TransferController;
use Illuminate\Support\Facades\Route;

/*
| Mounted under /api/v1 (see bootstrap/app.php). Every POST accepts an
| optional Idempotency-Key header.
*/

Route::pattern('accountId', '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}');
Route::pattern('transferId', '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}');

Route::middleware('idempotent')->group(function (): void {
    Route::post('accounts', [AccountController::class, 'open'])->name('accounts.open');
    Route::post('accounts/{accountId}/deposits', [AccountController::class, 'deposit'])->name('accounts.deposit');
    Route::post('accounts/{accountId}/withdrawals', [AccountController::class, 'withdraw'])->name('accounts.withdraw');
    Route::post('accounts/{accountId}/freeze', [AccountController::class, 'freeze'])->name('accounts.freeze');
    Route::post('accounts/{accountId}/unfreeze', [AccountController::class, 'unfreeze'])->name('accounts.unfreeze');
    Route::post('transfers', [TransferController::class, 'store'])->name('transfers.store');
});

Route::get('accounts/{accountId}', [AccountController::class, 'show'])->name('accounts.show');
Route::get('accounts/{accountId}/balance', [AccountController::class, 'balance'])->name('accounts.balance');
Route::get('accounts/{accountId}/statement', [AccountController::class, 'statement'])->name('accounts.statement');
Route::get('transfers/{transferId}', [TransferController::class, 'show'])->name('transfers.show');
Route::get('ledger/trial-balance', [LedgerController::class, 'trialBalance'])->name('ledger.trial-balance');
