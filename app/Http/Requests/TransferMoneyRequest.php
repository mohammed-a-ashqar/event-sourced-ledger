<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Ledger\Application\Transfer\TransferMoney;
use Ledger\Domain\Account\AccountId;
use Ledger\Domain\Transfer\TransferId;

final class TransferMoneyRequest extends ApiRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'source_account_id' => ['required', 'uuid'],
            'destination_account_id' => ['required', 'uuid', 'different:source_account_id'],
            'amount' => self::amountRules(),
            'currency' => self::currencyRules(),
        ];
    }

    public function toCommand(TransferId $transferId): TransferMoney
    {
        return new TransferMoney(
            $transferId,
            AccountId::fromString($this->validatedString('source_account_id')),
            AccountId::fromString($this->validatedString('destination_account_id')),
            $this->validatedInt('amount'),
            $this->validatedString('currency'),
        );
    }
}
