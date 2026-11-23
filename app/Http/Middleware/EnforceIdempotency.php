<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\ApiError;
use App\Http\Idempotency\IdempotencyStore;
use App\Http\Idempotency\StoredResponse;
use Closure;
use Illuminate\Http\Request;
use Ledger\EventSourcing\Clock\Clock;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Makes POST requests safe to retry with an Idempotency-Key header.
 *
 *  - first request with a key: executed, response stored (unless 5xx);
 *  - same key, same request:   stored response replayed, nothing executed;
 *  - same key, other request:  409 idempotency_key_reused;
 *  - same key while the first is still running: 409 idempotency_request_in_progress.
 */
final readonly class EnforceIdempotency
{
    public const HEADER = 'Idempotency-Key';

    public const REPLAYED_HEADER = 'Idempotent-Replayed';

    private const STORED_HEADERS = ['Content-Type', 'Location'];

    public function __construct(
        private IdempotencyStore $store,
        private Clock $clock,
        private int $ttlHours,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->headers->get(self::HEADER);

        if ($key === null) {
            return $next($request);
        }

        if (preg_match('/^[\x21-\x7E]{1,255}$/', $key) !== 1) {
            return ApiError::response(400, 'invalid_idempotency_key', 'Idempotency-Key must be 1 to 255 visible ASCII characters.');
        }

        $fingerprint = self::fingerprint($request);
        $existing = $this->store->find($key);

        if ($existing !== null && $existing->createdAt < $this->clock->now()->modify(sprintf('-%d hours', $this->ttlHours))) {
            $this->store->release($key);
            $existing = null;
        }

        if ($existing !== null) {
            return match (true) {
                $existing->fingerprint !== $fingerprint => ApiError::response(409, 'idempotency_key_reused', 'This Idempotency-Key was already used for a different request.'),
                $existing->response === null => ApiError::response(409, 'idempotency_request_in_progress', 'A request with this Idempotency-Key is still being processed.'),
                default => self::replay($existing->response),
            };
        }

        if (! $this->store->reserve($key, $fingerprint)) {
            return ApiError::response(409, 'idempotency_request_in_progress', 'A request with this Idempotency-Key is still being processed.');
        }

        return $this->execute($key, $request, $next);
    }

    /**
     * @param  Closure(Request): Response  $next
     */
    private function execute(string $key, Request $request, Closure $next): Response
    {
        try {
            $response = $next($request);
        } catch (Throwable $failure) {
            $this->store->release($key);

            throw $failure;
        }

        if ($response->getStatusCode() >= 500) {
            // Server errors are not a final answer: let the client retry for real.
            $this->store->release($key);

            return $response;
        }

        $this->store->complete($key, new StoredResponse(
            $response->getStatusCode(),
            (string) $response->getContent(),
            self::headersToStore($response),
        ));

        return $response;
    }

    private static function replay(StoredResponse $stored): Response
    {
        return new Response($stored->body, $stored->status, $stored->headers + [self::REPLAYED_HEADER => 'true']);
    }

    private static function fingerprint(Request $request): string
    {
        return hash('sha256', $request->method().' '.$request->path()."\n".json_encode(self::canonical($request->all()), JSON_THROW_ON_ERROR));
    }

    /**
     * Key order must not change the fingerprint: {"a":1,"b":2} == {"b":2,"a":1}.
     */
    private static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(self::canonical(...), $value);
    }

    /**
     * @return array<string, string>
     */
    private static function headersToStore(Response $response): array
    {
        $headers = [];

        foreach (self::STORED_HEADERS as $name) {
            $value = $response->headers->get($name);

            if ($value !== null) {
                $headers[$name] = $value;
            }
        }

        return $headers;
    }
}
