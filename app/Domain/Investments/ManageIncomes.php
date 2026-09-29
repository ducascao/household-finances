<?php

namespace App\Domain\Investments;

use App\Domain\Transactions\CreateTransaction;
use App\Domain\Transactions\TransactionData;
use App\Enums\AssetIncomeType;
use App\Enums\CategoryType;
use App\Models\Account;
use App\Models\Asset;
use App\Models\AssetIncome;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Proventos: cada um gera uma receita (valor líquido) na conta da corretora, ligada por asset_income_id.
 * Categoria padrão: a subcategoria de "Rendimentos" do tipo (Dividendos, JCP, Rendimentos de FII).
 */
class ManageIncomes
{
    /**
     * @param  array<string, mixed>  $data  type, date, gross_amount, withheld_tax, category_id?, notes?
     */
    public function register(User $actor, Asset $asset, array $data): AssetIncome
    {
        $this->ensureAccess($actor, $asset);
        $validated = $this->validate($data);

        return DB::transaction(function () use ($actor, $asset, $validated, $data): AssetIncome {
            $income = new AssetIncome([...$validated, 'asset_id' => $asset->id]);
            $income->household_id = $asset->household_id;
            $income->save();

            $this->syncTransaction($actor, $asset, $income, $data['category_id'] ?? null);

            return $income;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(User $actor, AssetIncome $income, array $data): AssetIncome
    {
        $asset = Asset::withoutGlobalScopes()->findOrFail($income->asset_id);
        $this->ensureAccess($actor, $asset);
        $validated = $this->validate($data);

        return DB::transaction(function () use ($actor, $asset, $income, $validated, $data): AssetIncome {
            $income->fill($validated)->save();
            $this->syncTransaction($actor, $asset, $income, $data['category_id'] ?? null);

            return $income;
        });
    }

    public function delete(User $actor, AssetIncome $income): void
    {
        $asset = Asset::withoutGlobalScopes()->findOrFail($income->asset_id);
        $this->ensureAccess($actor, $asset);

        DB::transaction(function () use ($income): void {
            Transaction::withoutGlobalScopes()->where('asset_income_id', $income->id)->get()->each->delete();
            $income->delete();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{type: AssetIncomeType, date: Carbon, gross_amount: int, withheld_tax: int, notes: string|null}
     */
    private function validate(array $data): array
    {
        $data['type'] = ($data['type'] ?? null) instanceof AssetIncomeType ? $data['type']->value : ($data['type'] ?? null);
        $data['withheld_tax'] ??= 0;

        /** @var array{type: string, date: string, gross_amount: int, withheld_tax: int, notes?: string|null} $validated */
        $validated = Validator::make($data, [
            'type' => ['required', Rule::enum(AssetIncomeType::class)],
            'date' => ['required', 'date'],
            'gross_amount' => ['required', 'integer', 'min:1'],
            'withheld_tax' => ['integer', 'min:0', 'lt:gross_amount'],
            'notes' => ['nullable', 'string'],
        ], ['withheld_tax.lt' => 'O IR retido deve ser menor que o valor bruto.'], [
            'type' => 'tipo', 'date' => 'data de pagamento', 'gross_amount' => 'valor bruto', 'withheld_tax' => 'IR retido',
        ])->validate();

        return [
            'type' => AssetIncomeType::from($validated['type']),
            'date' => Carbon::parse($validated['date'])->startOfDay(),
            'gross_amount' => (int) $validated['gross_amount'],
            'withheld_tax' => (int) $validated['withheld_tax'],
            'notes' => ($validated['notes'] ?? null) ?: null,
        ];
    }

    private function syncTransaction(User $actor, Asset $asset, AssetIncome $income, mixed $categoryId): void
    {
        $existing = Transaction::withoutGlobalScopes()->where('asset_income_id', $income->id)->first();
        $categoryId = $categoryId ?: ($existing->category_id ?? $this->defaultCategory($asset->household_id, $income->type));

        if ($categoryId === null) {
            throw ValidationException::withMessages(['category_id' => 'Escolha uma categoria de receita.']);
        }

        $category = Category::withoutGlobalScopes()->where('household_id', $asset->household_id)->find($categoryId);

        if ($category === null || $category->type !== CategoryType::Income) {
            throw ValidationException::withMessages(['category_id' => 'Escolha uma categoria de receita.']);
        }

        $description = $income->type->label().' '.$asset->ticker;
        $data = [
            'account_id' => $asset->account_id,
            'category_id' => $category->id,
            'amount' => $income->netAmount(),
            'date' => $income->date->toDateString(),
            'description' => $description,
            'paid_by' => $existing->paid_by ?? $actor->id,
            'notes' => $income->withheld_tax > 0 ? 'Bruto '.number_format($income->gross_amount / 100, 2, ',', '.').', IR retido '.number_format($income->withheld_tax / 100, 2, ',', '.') : null,
        ];

        if ($existing === null) {
            $transaction = app(CreateTransaction::class)->execute($actor, $data);
            $transaction->forceFill(['asset_income_id' => $income->id])->save();

            return;
        }

        $attributes = TransactionData::resolve($actor, $data, Account::withoutGlobalScopes()->find($existing->account_id));
        unset($attributes['household_id']);
        $existing->fill($attributes)->save();
    }

    private function defaultCategory(int $householdId, AssetIncomeType $type): ?int
    {
        $id = Category::withoutGlobalScopes()
            ->where('household_id', $householdId)
            ->where('type', CategoryType::Income->value)
            ->where('name', $type->categoryName())
            ->value('id')
            ?? Category::withoutGlobalScopes()
                ->where('household_id', $householdId)
                ->where('type', CategoryType::Income->value)
                ->whereNull('parent_id')
                ->where('name', 'Rendimentos')
                ->value('id');

        return $id !== null ? (int) $id : null;
    }

    private function ensureAccess(User $actor, Asset $asset): void
    {
        $account = Account::withoutGlobalScopes()->find($asset->account_id);

        if ($account === null || ! $account->isVisibleTo($actor)) {
            throw ValidationException::withMessages(['asset' => 'Sem acesso a este ativo.']);
        }
    }
}
