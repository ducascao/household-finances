<?php

namespace App\Domain\Investments;

use App\Enums\AccountType;
use App\Enums\AssetType;
use App\Models\Account;
use App\Models\Asset;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SaveAsset
{
    /**
     * @param  array<string, mixed>  $data  account_id, type, ticker, name
     */
    public function execute(User $actor, array $data, ?Asset $asset = null): Asset
    {
        $data['type'] = ($data['type'] ?? null) instanceof AssetType ? $data['type']->value : ($data['type'] ?? null);
        $data['ticker'] = strtoupper(trim((string) ($data['ticker'] ?? '')));

        /** @var array{account_id: int, type: string, ticker: string, name: string} $validated */
        $validated = Validator::make($data, [
            'account_id' => ['required', 'integer'],
            'type' => ['required', Rule::enum(AssetType::class)],
            'ticker' => ['required', 'string', 'max:20', 'regex:/^[A-Z0-9.]+$/'],
            'name' => ['required', 'string', 'max:255'],
        ], ['ticker.regex' => 'Ticker só com letras e números (ex.: PETR4, HGLG11).'], [
            'account_id' => 'corretora', 'type' => 'tipo', 'ticker' => 'ticker', 'name' => 'nome',
        ])->validate();

        $account = Account::withoutGlobalScopes()->find($validated['account_id']);

        if ($account === null || ! $account->isVisibleTo($actor)) {
            throw ValidationException::withMessages(['account_id' => 'Conta não encontrada.']);
        }

        if ($account->type !== AccountType::Brokerage) {
            throw ValidationException::withMessages(['account_id' => 'Escolha uma conta do tipo corretora.']);
        }

        if ($asset !== null && $asset->account_id !== $account->id && $asset->operations()->withoutGlobalScopes()->exists()) {
            throw ValidationException::withMessages(['account_id' => 'O ativo já tem operações: não é possível trocar a corretora.']);
        }

        $duplicate = Asset::withoutGlobalScopes()
            ->where('account_id', $account->id)
            ->where('ticker', $validated['ticker'])
            ->when($asset, fn ($query) => $query->whereKeyNot($asset->id))
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages(['ticker' => 'Este ticker já está cadastrado nesta corretora.']);
        }

        $asset ??= new Asset;
        $asset->household_id = $account->household_id;
        $asset->fill([...$validated, 'currency' => $account->currency])->save();

        return $asset;
    }
}
