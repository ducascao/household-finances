<?php

namespace App\Domain\Import;

use App\Models\Account;
use App\Models\ImportProfile;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class SaveImportProfile
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(User $actor, array $data, ?ImportProfile $profile = null): ImportProfile
    {
        /** @var array<string, mixed> $validated */
        $validated = Validator::make($data, [
            'account_id' => ['required', 'integer'],
            'name' => ['required', 'string', 'max:255'],
            'delimiter' => ['required', 'string', 'size:1'],
            'skip_lines' => ['nullable', 'integer', 'min:0', 'max:50'],
            'has_header' => ['nullable', 'boolean'],
            'date_column' => ['required', 'integer', 'min:1'],
            'description_column' => ['required', 'integer', 'min:1'],
            'amount_column' => ['nullable', 'required_without_all:debit_column,credit_column', 'integer', 'min:1'],
            'debit_column' => ['nullable', 'integer', 'min:1'],
            'credit_column' => ['nullable', 'integer', 'min:1'],
            'date_format' => ['required', 'string', 'max:20'],
            'decimal_separator' => ['required', 'in:",","."'],
            'thousands_separator' => ['nullable', 'in:",",".", "'],
            'invert_sign' => ['nullable', 'boolean'],
        ], [
            'amount_column.required_without_all' => 'Informe a coluna de valor ou as colunas de débito/crédito.',
        ], [
            'account_id' => 'conta',
            'date_column' => 'coluna da data',
            'description_column' => 'coluna da descrição',
            'amount_column' => 'coluna do valor',
            'date_format' => 'formato da data',
            'decimal_separator' => 'separador decimal',
        ])->validate();

        $account = Account::withoutGlobalScopes()->find($validated['account_id']);

        if ($account === null || ! $account->isVisibleTo($actor)) {
            throw ValidationException::withMessages(['account_id' => 'Conta não encontrada.']);
        }

        $taken = ImportProfile::withoutGlobalScopes()
            ->where('account_id', $account->id)
            ->when($profile, fn ($query) => $query->whereKeyNot($profile->id))
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages(['account_id' => 'Esta conta já tem um perfil de CSV.']);
        }

        $profile ??= new ImportProfile;
        $profile->household_id = $account->household_id;
        $profile->fill([
            ...$validated,
            'skip_lines' => (int) ($validated['skip_lines'] ?? 0),
            'has_header' => (bool) ($validated['has_header'] ?? false),
            'invert_sign' => (bool) ($validated['invert_sign'] ?? false),
            'thousands_separator' => ($validated['thousands_separator'] ?? null) ?: null,
        ])->save();

        return $profile;
    }
}
