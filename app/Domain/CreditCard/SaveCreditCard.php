<?php

namespace App\Domain\CreditCard;

use App\Models\Account;
use App\Models\CreditCard;
use App\Models\Invoice;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Mantém a configuração de cartão (fechamento, vencimento, limite) de uma conta do tipo credit_card.
 * Mudar os dias vale para as faturas ainda não criadas; as existentes mantêm suas datas.
 */
class SaveCreditCard
{
    /**
     * @param  array<string, mixed>  $data  card_closing_day, card_due_day, card_limit (centavos)
     */
    public function execute(Account $account, array $data): ?CreditCard
    {
        $card = CreditCard::withoutGlobalScopes()->where('account_id', $account->id)->first();

        if (! $account->isCreditCard()) {
            if ($card !== null) {
                if (Invoice::withoutGlobalScopes()->where('credit_card_id', $card->id)->exists()) {
                    throw ValidationException::withMessages([
                        'type' => 'A conta tem faturas de cartão: não é possível mudar o tipo.',
                    ]);
                }

                $card->delete();
            }

            return null;
        }

        /** @var array{card_closing_day: int, card_due_day: int, card_limit: int|null} $validated */
        $validated = Validator::make($data, [
            'card_closing_day' => ['required', 'integer', 'between:1,31'],
            'card_due_day' => ['required', 'integer', 'between:1,31'],
            'card_limit' => ['nullable', 'integer', 'min:0'],
        ], attributes: [
            'card_closing_day' => 'dia de fechamento',
            'card_due_day' => 'dia de vencimento',
            'card_limit' => 'limite',
        ])->validate();

        $card ??= new CreditCard(['account_id' => $account->id]);
        $card->household_id = $account->household_id;
        $card->fill([
            'closing_day' => (int) $validated['card_closing_day'],
            'due_day' => (int) $validated['card_due_day'],
            'limit' => (int) ($validated['card_limit'] ?? 0),
            'currency' => $account->currency,
        ])->save();

        return $card;
    }
}
