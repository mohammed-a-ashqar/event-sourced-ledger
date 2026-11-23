<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

/*
| Background work. Run `php artisan schedule:work` (or a cron entry calling
| `schedule:run`) to enable it. withoutOverlapping() keeps a single relay
| and a single saga recoverer active at a time.
*/

Schedule::command('ledger:outbox:relay')->everyMinute()->withoutOverlapping();
Schedule::command('ledger:transfers:resume --older-than=60')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('ledger:projections:run')->everyMinute()->withoutOverlapping()
    ->skip(fn (): bool => config('ledger.projections.sync') === true);
