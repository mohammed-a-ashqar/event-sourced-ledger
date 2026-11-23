<?php

declare(strict_types=1);

namespace App\Http\Requests;

/**
 * Body of a deposit or a withdrawal.
 */
final class MoneyMovementRequest extends ApiRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'amount' => self::amountRules(),
            'currency' => self::currencyRules(),
            'reference' => ['nullable', 'string', 'max:140'],
        ];
    }

    public function amount(): int
    {
        return $this->validatedInt('amount');
    }

    public function currency(): string
    {
        return $this->validatedString('currency');
    }

    public function reference(): ?string
    {
        return $this->validatedNullableString('reference');
    }
}
