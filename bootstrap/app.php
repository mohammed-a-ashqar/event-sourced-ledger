<?php

declare(strict_types=1);

use App\Http\ApiExceptionRenderer;
use App\Http\Middleware\AssignCorrelationId;
use App\Http\Middleware\EnforceIdempotency;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Ledger\Domain\Shared\BusinessRuleViolation;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: 'api/v1',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(prepend: [AssignCorrelationId::class]);
        $middleware->alias(['idempotent' => EnforceIdempotency::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Rule violations are expected outcomes, not incidents.
        $exceptions->dontReport([BusinessRuleViolation::class]);

        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return (new ApiExceptionRenderer((bool) config('app.debug')))->render($e);
        });
    })
    ->create();
