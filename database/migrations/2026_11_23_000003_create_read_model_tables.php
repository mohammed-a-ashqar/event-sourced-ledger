<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Read models are disposable: every table below can be dropped and rebuilt
 * from the event store with `php artisan ledger:projections:rebuild`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projection_checkpoints', function (Blueprint $table): void {
            $table->string('projection', 100)->primary();
            $table->unsignedBigInteger('position');
            $table->dateTime('updated_at', 6);
        });

        Schema::create('account_balances', function (Blueprint $table): void {
            $table->uuid('account_id')->primary();
            $table->string('owner_name', 120);
            $table->char('currency', 3);
            $table->bigInteger('balance');
            $table->bigInteger('overdraft_limit');
            $table->string('status', 16);
            $table->unsignedInteger('version');
            $table->dateTime('opened_at', 6);
            $table->dateTime('updated_at', 6);
        });

        Schema::create('account_statement_lines', function (Blueprint $table): void {
            $table->id();
            $table->uuid('account_id');
            $table->uuid('event_id')->unique();
            $table->unsignedBigInteger('position');
            $table->string('entry_type', 32);
            $table->bigInteger('amount');
            $table->bigInteger('balance_after');
            $table->char('currency', 3);
            $table->uuid('reference_id');
            $table->string('description', 255)->nullable();
            $table->dateTime('occurred_at', 6);

            $table->index(['account_id', 'position']);
        });

        Schema::create('journal_lines', function (Blueprint $table): void {
            $table->id();
            $table->uuid('entry_id');
            $table->unsignedSmallInteger('line_number');
            $table->string('ledger_account', 120);
            $table->string('direction', 6);
            $table->bigInteger('amount');
            $table->char('currency', 3);
            $table->unsignedBigInteger('position');
            $table->dateTime('occurred_at', 6);

            $table->unique(['entry_id', 'line_number']);
            $table->index(['ledger_account', 'currency']);
        });

        Schema::create('transfers', function (Blueprint $table): void {
            $table->uuid('transfer_id')->primary();
            $table->uuid('source_account_id');
            $table->uuid('destination_account_id');
            $table->bigInteger('amount');
            $table->char('currency', 3);
            $table->string('status', 16);
            $table->string('failure_reason', 255)->nullable();
            $table->dateTime('initiated_at', 6);
            $table->dateTime('updated_at', 6);

            $table->index(['status', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transfers');
        Schema::dropIfExists('journal_lines');
        Schema::dropIfExists('account_statement_lines');
        Schema::dropIfExists('account_balances');
        Schema::dropIfExists('projection_checkpoints');
    }
};
