<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbox_messages', function (Blueprint $table): void {
            $table->id();
            // Equal to the id of the domain event it was derived from: consumers dedupe on it.
            $table->uuid('message_id')->unique();
            $table->string('message_type', 100);
            $table->json('payload');
            $table->json('headers');
            $table->dateTime('occurred_at', 6);
            $table->dateTime('published_at', 6)->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();

            $table->index(['published_at', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox_messages');
    }
};
