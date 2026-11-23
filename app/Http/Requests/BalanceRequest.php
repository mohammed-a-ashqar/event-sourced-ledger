<?php

declare(strict_types=1);

namespace App\Http\Requests;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Query string of GET /accounts/{id}/balance?as_of=2026-03-01T12:00:00Z
 */
final class BalanceRequest extends ApiRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'as_of' => ['sometimes', 'date'],
        ];
    }

    public function asOf(): ?DateTimeImmutable
    {
        $asOf = $this->validatedNullableString('as_of');

        return $asOf === null ? null : (new DateTimeImmutable($asOf))->setTimezone(new DateTimeZone('UTC'));
    }
}
