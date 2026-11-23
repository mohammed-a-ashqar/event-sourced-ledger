<?php

declare(strict_types=1);

namespace App\Http\Idempotency;

final readonly class StoredResponse
{
    /**
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public int $status,
        public string $body,
        public array $headers,
    ) {}
}
