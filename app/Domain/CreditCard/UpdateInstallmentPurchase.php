<?php

namespace App\Domain\CreditCard;

use App\Models\Account;
use App\Models\Category;
use App\Models\InstallmentGroup;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class UpdateInstallmentPurchase
{
    /**
     * Altera descrição e categoria de todas as parcelas. Valor, número de parcelas e data não mudam:
     * para isso, exclua e lance de novo.
     *
     * @param  array<string, mixed>  $data  description, category_id
     */
    public function execute(User $actor, InstallmentGroup $group, array $data): InstallmentGroup
    {
        $account = Account::withoutGlobalScopes()->find($group->account_id);

        if ($account === null || ! $account->isVisibleTo($actor)) {
            throw ValidationException::withMessages(['installment_group' => 'Sem acesso a esta compra.']);
        }

        /** @var array{description: string, category_id: int} $validated */
        $validated = Validator::make($data, [
            'description' => ['required', 'string', 'max:240'],
            'category_id' => ['required', 'integer'],
        ], attributes: ['description' => 'descrição', 'category_id' => 'categoria'])->validate();

        $category = Category::withoutGlobalScopes()->where('household_id', $group->household_id)->find($validated['category_id']);
        $current = Category::withoutGlobalScopes()->find($group->category_id);

        if ($category === null || $category->type !== $current?->type) {
            throw ValidationException::withMessages(['category_id' => 'Escolha uma categoria do mesmo tipo.']);
        }

        DB::transaction(function () use ($group, $validated, $category): void {
            $group->fill(['description' => trim($validated['description']), 'category_id' => $category->id])->save();

            Transaction::withoutGlobalScopes()
                ->where('installment_group_id', $group->id)
                ->get()
                ->each(fn (Transaction $transaction) => $transaction->fill([
                    'description' => "{$group->description} ({$transaction->installment_number}/{$group->installments})",
                    'category_id' => $category->id,
                ])->save());
        });

        return $group;
    }
}
