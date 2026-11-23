<?php

declare(strict_types=1);

return [

    /*
    | Take a snapshot of an account every N events. Snapshots only speed up
    | loading; deleting them is always safe.
    */
    'snapshot_every' => (int) env('LEDGER_SNAPSHOT_EVERY', 50),

    /*
    | How many times a command is attempted when it loses an optimistic
    | concurrency race before the conflict is reported to the caller.
    */
    'command_max_attempts' => (int) env('LEDGER_COMMAND_MAX_ATTEMPTS', 3),

    'projections' => [
        /*
        | true: projectors catch up right after every command, so the API can
        | read its own writes. false: run `php artisan ledger:projections:run`
        | from a worker or the scheduler (eventually consistent reads).
        */
        'sync' => (bool) env('LEDGER_SYNC_PROJECTIONS', true),
        'batch_size' => 500,
    ],

    'outbox' => [
        // "log" writes messages to the application log; "memory" keeps them in-process (tests).
        'publisher' => env('LEDGER_OUTBOX_PUBLISHER', 'log'),
        'max_attempts' => (int) env('LEDGER_OUTBOX_MAX_ATTEMPTS', 10),
    ],

    'idempotency' => [
        'ttl_hours' => (int) env('LEDGER_IDEMPOTENCY_TTL_HOURS', 24),
    ],

];
