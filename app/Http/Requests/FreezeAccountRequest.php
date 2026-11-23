<?php

declare(strict_types=1);

namespace App\Http\Requests;

final class FreezeAccountRequest extends ApiRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:1', 'max:255'],
        ];
    }

    public function reason(): string
    {
        return $this->validatedString('reason');
    }
}
