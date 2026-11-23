<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Ledger\Application\Account\OpenAccount;
use Ledger\Domain\Account\AccountId;

final class OpenAccountRequest extends ApiRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'owner_name' => ['required', 'string', 'min:1', 'max:120'],
            'currency' => self::currencyRules(),
            'overdraft_limit' => ['sometimes', 'integer', 'min:0', 'max:'.self::MAX_AMOUNT],
        ];
    }

    public function toCommand(AccountId $accountId): OpenAccount
    {
        return new OpenAccount(
            $accountId,
            $this->validatedString('owner_name'),
            $this->validatedString('currency'),
            $this->validatedInt('overdraft_limit'),
        );
    }
}
