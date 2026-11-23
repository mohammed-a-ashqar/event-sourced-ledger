<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Ledger\EventSourcing\CorrelationContext;
use Ledger\EventSourcing\Uuid;
use Symfony\Component\HttpFoundation\Response;

/**
 * Accepts an X-Correlation-ID from the caller (or creates one), stamps it
 * on every event written during the request and echoes it back.
 */
final readonly class AssignCorrelationId
{
    public const HEADER = 'X-Correlation-ID';

    public function __construct(private CorrelationContext $context) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $incoming = $request->headers->get(self::HEADER);
        $correlationId = is_string($incoming) && preg_match('/^[A-Za-z0-9._:-]{1,100}$/', $incoming) === 1
            ? $incoming
            : Uuid::v4();

        $this->context->useCorrelationId($correlationId);

        $response = $next($request);
        $response->headers->set(self::HEADER, $correlationId);

        return $response;
    }
}
