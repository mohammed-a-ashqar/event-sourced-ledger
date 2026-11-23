<?php

declare(strict_types=1);

namespace App\Http;

use Illuminate\Http\JsonResponse;

/**
 * The one error format of the API:
 *
 *   {"error": {"code": "insufficient_funds", "message": "...", "details": {...}}}
 *
 * "code" is stable and meant for programs; "message" is for humans.
 */
final class ApiError
{
    /**
     * @param  array<string, mixed>  $details
     */
    public static function response(int $status, string $code, string $message, array $details = []): JsonResponse
    {
        $error = ['code' => $code, 'message' => $message];

        if ($details !== []) {
            $error['details'] = $details;
        }

        return new JsonResponse(['error' => $error], $status);
    }
}
