<?php

namespace App\Domain\Investments;

use App\Enums\AssetOperationType;
use App\Enums\TransactionStatus;
use App\Models\Account;
use App\Models\Asset;
use App\Models\AssetOperation;
use App\Models\Transaction;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Lança, altera e exclui operações. Antes de gravar, recalcula a posição inteira: nenhuma mudança pode
 * deixar a quantidade negativa em algum ponto da história. Compra e venda mantêm um lançamento na
 * conta da corretora (sem categoria, fora dos relatórios de receita e despesa).
 */
class ManageOperations
{
    public function __construct(
        private readonly PositionCalculator $calculator,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function register(User $actor, Asset $asset, array $data): AssetOperation
    {
        $this->ensureAccess($actor, $asset);
        $attributes = OperationData::resolve($data);

        $operation = new AssetOperation([...$attributes, 'asset_id' => $asset->id]);
        $operation->household_id = $asset->household_id;

        $this->ensureValid($asset, $operation);

        return DB::transaction(function () use ($actor, $asset, $operation): AssetOperation {
            $operation->save();
            $this->syncTransaction($actor, $asset, $operation);

            return $operation;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(User $actor, AssetOperation $operation, array $data): AssetOperation
    {
        $asset = Asset::withoutGlobalScopes()->findOrFail($operation->asset_id);
        $this->ensureAccess($actor, $asset);

        $operation->fill(OperationData::resolve($data));
        $this->ensureValid($asset, $operation, replacing: $operation);

        return DB::transaction(function () use ($actor, $asset, $operation): AssetOperation {
            $operation->save();
            $this->syncTransaction($actor, $asset, $operation);

            return $operation;
        });
    }

    public function delete(User $actor, AssetOperation $operation): void
    {
        $asset = Asset::withoutGlobalScopes()->findOrFail($operation->asset_id);
        $this->ensureAccess($actor, $asset);
        $this->ensureValid($asset, null, replacing: $operation);

        DB::transaction(function () use ($operation): void {
            // Pelo model, para disparar a limpeza de comprovantes do lançamento.
            Transaction::withoutGlobalScopes()->where('asset_operation_id', $operation->id)->get()->each->delete();
            $operation->delete();
        });
    }

    /**
     * Valor do lançamento em centavos: compra = −(qtd × preço + taxas); venda = qtd × preço − taxas.
     */
    public static function cashAmount(AssetOperation $operation): int
    {
        $gross = BigDecimal::of((string) $operation->quantity)
            ->multipliedBy((string) $operation->unit_price)
            ->multipliedBy(100)
            ->toScale(0, RoundingMode::HalfUp)
            ->toInt();

        return $operation->type === AssetOperationType::Buy ? -($gross + $operation->fees) : $gross - $operation->fees;
    }

    private function syncTransaction(User $actor, Asset $asset, AssetOperation $operation): void
    {
        $existing = Transaction::withoutGlobalScopes()->where('asset_operation_id', $operation->id)->first();

        if (! $operation->type->isTrade()) {
            $existing?->delete();

            return;
        }

        $transaction = $existing ?? new Transaction;
        $transaction->household_id = $asset->household_id;
        $transaction->fill([
            'asset_operation_id' => $operation->id,
            'account_id' => $asset->account_id,
            'category_id' => null,
            'amount' => self::cashAmount($operation),
            'currency' => $asset->currency,
            'status' => TransactionStatus::Paid,
            'date' => $operation->date,
            'competence_date' => $operation->date->copy()->startOfMonth(),
            'description' => $operation->type->label().' de '.Quantity::format((string) $operation->quantity).' '.$asset->ticker,
            'paid_by' => $existing->paid_by ?? $actor->id,
        ])->save();
    }

    private function ensureValid(Asset $asset, ?AssetOperation $candidate, ?AssetOperation $replacing = null): void
    {
        $operations = AssetOperation::withoutGlobalScopes()
            ->where('asset_id', $asset->id)
            ->when($replacing?->exists, fn ($query) => $query->whereKeyNot($replacing->id))
            ->get();

        if ($candidate !== null) {
            $operations->push($candidate);
        }

        try {
            $this->calculator->calculate($operations);
        } catch (InvalidPosition $e) {
            throw ValidationException::withMessages(['quantity' => $e->getMessage()]);
        }
    }

    private function ensureAccess(User $actor, Asset $asset): void
    {
        $account = Account::withoutGlobalScopes()->find($asset->account_id);

        if ($account === null || ! $account->isVisibleTo($actor)) {
            throw ValidationException::withMessages(['asset' => 'Sem acesso a este ativo.']);
        }
    }
}
