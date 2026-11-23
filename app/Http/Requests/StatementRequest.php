<?php

declare(strict_types=1);

namespace App\Http\Requests;

/**
 * Query string of GET /accounts/{id}/statement?after=<position>&limit=<n>
 */
final class StatementRequest extends ApiRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'after' => ['sometimes', 'integer', 'min:0'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function afterPosition(): int
    {
        return $this->validatedInt('after');
    }

    public function limit(): int
    {
        return $this->validatedInt('limit', 50);
    }
}
