<?php

declare(strict_types=1);

namespace App\Http\Idempotency;

use DateTimeImmutable;

final readonly class IdempotencyRecord
{
    public function __construct(
        public string $fingerprint,
        public DateTimeImmutable $createdAt,
        public ?StoredResponse $response,
    ) {}

    public function isCompleted(): bool
    {
        return $this->response !== null;
    }
}
