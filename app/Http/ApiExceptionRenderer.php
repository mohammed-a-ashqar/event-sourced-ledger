<?php

declare(strict_types=1);

namespace App\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Ledger\Domain\Shared\BusinessRuleViolation;
use Ledger\EventSourcing\ConcurrencyException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Translates exceptions into the API error format. Domain exceptions carry
 * their own stable code; only the HTTP status is decided here.
 */
final readonly class ApiExceptionRenderer
{
    /** @var array<string, int> */
    private const STATUS_BY_CODE = [
        'account_not_found' => 404,
        'transfer_not_found' => 404,
        'account_already_exists' => 409,
        'account_frozen' => 409,
        'account_not_frozen' => 409,
        'illegal_transfer_transition' => 409,
    ];

    public function __construct(private bool $debug) {}

    public function render(Throwable $e): JsonResponse
    {
        return match (true) {
            $e instanceof ValidationException => ApiError::response(422, 'validation_failed', 'The request is invalid.', $e->errors()),
            $e instanceof BusinessRuleViolation => ApiError::response(self::STATUS_BY_CODE[$e->errorCode()] ?? 422, $e->errorCode(), $e->getMessage()),
            $e instanceof ConcurrencyException => ApiError::response(409, 'concurrency_conflict', 'The resource was modified concurrently; retry the request.'),
            $e instanceof HttpExceptionInterface => $this->httpError($e),
            default => ApiError::response(500, 'internal_error', 'An unexpected error occurred.', $this->debug ? ['exception' => $e::class, 'detail' => $e->getMessage()] : []),
        };
    }

    private function httpError(HttpExceptionInterface $e): JsonResponse
    {
        $status = $e->getStatusCode();
        [$code, $message] = match ($status) {
            404 => ['not_found', 'The requested resource does not exist.'],
            405 => ['method_not_allowed', 'This method is not allowed on this resource.'],
            default => ['http_error', $e->getMessage() !== '' ? $e->getMessage() : 'The request could not be processed.'],
        };

        $response = ApiError::response($status, $code, $message);
        $response->headers->add($e->getHeaders());

        return $response;
    }
}
