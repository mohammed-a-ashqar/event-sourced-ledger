<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stored_events', function (Blueprint $table): void {
            // Global, monotonically increasing position: the order projections read in.
            $table->id('position');
            $table->string('stream_id', 100);
            $table->unsignedInteger('version');
            $table->string('event_type', 100);
            $table->unsignedSmallInteger('schema_version');
            $table->json('payload');
            $table->json('metadata');
            $table->dateTime('occurred_at', 6);

            // Optimistic concurrency: two writers can never append the same version.
            $table->unique(['stream_id', 'version']);
            $table->index(['stream_id', 'occurred_at']);
        });

        Schema::create('snapshots', function (Blueprint $table): void {
            $table->string('stream_id', 100)->primary();
            $table->unsignedInteger('version');
            $table->unsignedSmallInteger('schema_version');
            $table->json('state');
            $table->dateTime('created_at', 6);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('snapshots');
        Schema::dropIfExists('stored_events');
    }
};
