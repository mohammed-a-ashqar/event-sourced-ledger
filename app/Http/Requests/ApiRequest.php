<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Ledger\Domain\Shared\Currency;
use LogicException;

/**
 * Base for API requests: typed access to validated input, and shared rules.
 */
abstract class ApiRequest extends FormRequest
{
    /**
     * Upper bound for any amount in minor units (10^15), well inside 64-bit range,
     * so sums of many amounts cannot overflow.
     */
    protected const MAX_AMOUNT = 1_000_000_000_000_000;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return list<string>
     */
    protected static function amountRules(): array
    {
        return ['required', 'integer', 'min:1', 'max:'.self::MAX_AMOUNT];
    }

    /**
     * @return list<string>
     */
    protected static function currencyRules(): array
    {
        return ['required', 'string', 'in:'.implode(',', Currency::supportedCodes())];
    }

    protected function validatedString(string $key): string
    {
        $value = $this->validated($key);

        return is_string($value) ? $value : throw new LogicException(sprintf('"%s" was not validated as a string.', $key));
    }

    protected function validatedNullableString(string $key): ?string
    {
        $value = $this->validated($key);

        return $value === null ? null : $this->validatedString($key);
    }

    protected function validatedInt(string $key, int $default = 0): int
    {
        $value = $this->validated($key, $default);

        return is_numeric($value) ? (int) $value : throw new LogicException(sprintf('"%s" was not validated as an integer.', $key));
    }
}
