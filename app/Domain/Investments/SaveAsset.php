<?php

namespace App\Domain\Investments;

use App\Enums\AccountType;
use App\Enums\AssetType;
use App\Enums\Indexer;
use App\Models\Account;
use App\Models\Asset;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Cadastro de ativos. Ativos da B3 (ação, FII, ETF, BDR) exigem ticker e conta do tipo corretora.
 * Renda fixa e previdência não têm ticker, têm campos informativos e aceitam corretora, conta corrente
 * ou poupança (a conta de onde saem os aportes e para onde voltam os resgates).
 */
class SaveAsset
{
    /**
     * @param  array<string, mixed>  $data  account_id, type, ticker, name, issuer, indexer, rate, maturity_date
     */
    public function execute(User $actor, array $data, ?Asset $asset = null): Asset
    {
        $data['type'] = ($data['type'] ?? null) instanceof AssetType ? $data['type']->value : ($data['type'] ?? null);
        $data['indexer'] = ($data['indexer'] ?? null) instanceof Indexer ? $data['indexer']->value : ($data['indexer'] ?? null);
        $type = AssetType::tryFrom((string) $data['type']);
        $byBalance = $type?->isValuedByBalance() ?? false;
        $data['ticker'] = $byBalance ? null : (strtoupper(trim((string) ($data['ticker'] ?? ''))) ?: null);

        /** @var array{account_id: int, type: string, ticker: string|null, name: string, issuer?: string|null, indexer?: string|null, rate?: string|null, maturity_date?: string|null} $validated */
        $validated = Validator::make($data, [
            'account_id' => ['required', 'integer'],
            'type' => ['required', Rule::enum(AssetType::class)],
            'ticker' => [$byBalance ? 'nullable' : 'required', 'string', 'max:20', 'regex:/^[A-Z0-9.]+$/'],
            'name' => ['required', 'string', 'max:255'],
            'issuer' => ['nullable', 'string', 'max:255'],
            'indexer' => ['nullable', Rule::enum(Indexer::class)],
            'rate' => ['nullable', 'string', 'max:60'],
            'maturity_date' => ['nullable', 'date'],
        ], ['ticker.regex' => 'Ticker só com letras e números (ex.: PETR4, HGLG11).'], [
            'account_id' => 'conta', 'type' => 'tipo', 'ticker' => 'ticker', 'name' => 'nome',
            'issuer' => 'emissor', 'indexer' => 'indexador', 'rate' => 'taxa', 'maturity_date' => 'vencimento',
        ])->validate();

        $account = Account::withoutGlobalScopes()->find($validated['account_id']);

        if ($account === null || ! $account->isVisibleTo($actor)) {
            throw ValidationException::withMessages(['account_id' => 'Conta não encontrada.']);
        }

        $allowed = $byBalance ? [AccountType::Brokerage, AccountType::Checking, AccountType::Savings] : [AccountType::Brokerage];

        if (! in_array($account->type, $allowed, true)) {
            throw ValidationException::withMessages(['account_id' => $byBalance
                ? 'Escolha uma corretora, conta corrente ou poupança (de onde saem os aportes).'
                : 'Escolha uma conta do tipo corretora.']);
        }

        if ($asset !== null) {
            $hasOperations = $asset->operations()->withoutGlobalScopes()->exists() || $asset->valuations()->withoutGlobalScopes()->exists();

            if ($hasOperations && $asset->account_id !== $account->id) {
                throw ValidationException::withMessages(['account_id' => 'O ativo já tem operações: não é possível trocar a conta.']);
            }

            if ($hasOperations && $asset->type->isValuedByBalance() !== $byBalance) {
                throw ValidationException::withMessages(['type' => 'O ativo já tem operações: não é possível trocar entre B3 e renda fixa/previdência.']);
            }
        }

        if ($validated['ticker'] !== null) {
            $duplicate = Asset::withoutGlobalScopes()
                ->where('account_id', $account->id)
                ->where('ticker', $validated['ticker'])
                ->when($asset, fn ($query) => $query->whereKeyNot($asset->id))
                ->exists();

            if ($duplicate) {
                throw ValidationException::withMessages(['ticker' => 'Este ticker já está cadastrado nesta corretora.']);
            }
        }

        $details = AssetType::from($validated['type'])->isValuedByBalance()
            ? [
                'issuer' => ($validated['issuer'] ?? null) ?: null,
                'indexer' => $validated['indexer'] ?? null,
                'rate' => ($validated['rate'] ?? null) ?: null,
                'maturity_date' => $validated['maturity_date'] ?? null,
            ]
            : ['issuer' => null, 'indexer' => null, 'rate' => null, 'maturity_date' => null];

        $asset ??= new Asset;
        $asset->household_id = $account->household_id;
        $asset->fill([
            'account_id' => $account->id,
            'type' => $validated['type'],
            'ticker' => $validated['ticker'],
            'name' => $validated['name'],
            ...$details,
            'currency' => $account->currency,
        ])->save();

        return $asset;
    }
}
