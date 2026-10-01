<?php

namespace App\Domain\Accounts;

use App\Enums\AccountType;
use App\Enums\AccountVisibility;
use Brick\Money\Currency;
use Brick\Money\Exception\UnknownCurrencyException;
use Closure;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class AccountRules
{
    /**
     * @param  array<string, mixed>  $data
     * @return array{name: string, type: string, visibility: string, currency: string, initial_balance: int, balance_date: string|null}
     */
    public static function validate(array $data): array
    {
        $data = [
            ...$data,
            'type' => $data['type'] instanceof AccountType ? $data['type']->value : ($data['type'] ?? null),
            'visibility' => $data['visibility'] instanceof AccountVisibility ? $data['visibility']->value : ($data['visibility'] ?? null),
            'currency' => strtoupper((string) ($data['currency'] ?? 'BRL')),
            'initial_balance' => $data['initial_balance'] ?? 0,
            'balance_date' => filled($data['balance_date'] ?? null) ? $data['balance_date'] : null,
        ];

        /** @var array{name: string, type: string, visibility: string, currency: string, initial_balance: int, balance_date: string|null} */
        return Validator::make($data, [
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::enum(AccountType::class)],
            'visibility' => ['required', Rule::enum(AccountVisibility::class)],
            'currency' => ['required', 'string', 'size:3', function (string $attribute, mixed $value, Closure $fail): void {
                try {
                    Currency::of((string) $value);
                } catch (UnknownCurrencyException) {
                    $fail('Moeda desconhecida.');
                }
            }],
            'initial_balance' => ['required', 'integer'],
            'balance_date' => ['nullable', 'date', 'before_or_equal:today'],
        ], ['balance_date.before_or_equal' => 'A data do saldo inicial não pode ser futura.'], attributes: [
            'name' => 'nome',
            'type' => 'tipo',
            'visibility' => 'visibilidade',
            'currency' => 'moeda',
            'initial_balance' => 'saldo inicial',
            'balance_date' => 'data do saldo inicial',
        ])->validate();
    }
}
