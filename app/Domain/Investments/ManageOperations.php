<?php

namespace App\Domain\Investments;

use App\Enums\AssetOperationType;
use App\Enums\TransactionStatus;
use App\Models\Account;
use App\Models\Asset;
use App\Models\AssetOperation;
use App\Models\ManualValuation;
use App\Models\Transaction;
use App\Models\User;
use App\Support\MoneyFormatter;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Collection;
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
        if ($operation->type->isCashFlow()) {
            return -ValuationCalculator::signed($operation);
        }

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

        if (! $operation->type->isTrade() && ! $operation->type->isCashFlow()) {
            $existing?->delete();

            return;
        }

        $description = $operation->type->isCashFlow()
            ? $operation->type->label().($operation->type === AssetOperationType::Contribution ? ' em ' : ' de ').$asset->label()
            : $operation->type->label().' de '.Quantity::format((string) $operation->quantity).' '.$asset->label();

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
            'description' => $description,
            'paid_by' => $existing->paid_by ?? $actor->id,
        ])->save();
    }

    private function ensureValid(Asset $asset, ?AssetOperation $candidate, ?AssetOperation $replacing = null): void
    {
        if ($candidate !== null && ! array_key_exists($candidate->type->value, AssetOperationType::optionsFor($asset->type))) {
            throw ValidationException::withMessages(['type' => $asset->type->isValuedByBalance()
                ? 'Renda fixa e previdência usam aporte e resgate.'
                : 'Ativos da B3 usam compra, venda, desdobramento e grupamento.']);
        }

        $operations = AssetOperation::withoutGlobalScopes()
            ->where('asset_id', $asset->id)
            ->when($replacing?->exists, fn ($query) => $query->whereKeyNot($replacing->id))
            ->get();

        if ($candidate !== null) {
            $operations->push($candidate);
        }

        if ($asset->type->isValuedByBalance()) {
            $this->ensureWithdrawalsCovered($asset, $operations);

            return;
        }

        try {
            $this->calculator->calculate($operations);
        } catch (InvalidPosition $e) {
            throw ValidationException::withMessages(['quantity' => $e->getMessage()]);
        }
    }

    /**
     * Nenhum resgate pode passar do valor do ativo na data (saldo informado + aportes − resgates até ali).
     *
     * @param  Collection<int, AssetOperation>  $operations
     */
    private function ensureWithdrawalsCovered(Asset $asset, Collection $operations): void
    {
        $valuations = ManualValuation::withoutGlobalScopes()->where('asset_id', $asset->id)->get();
        $calculator = new ValuationCalculator;

        foreach ($operations->where('type', AssetOperationType::Withdrawal) as $withdrawal) {
            $before = $operations->reject(fn (AssetOperation $op): bool => $op === $withdrawal
                || ($op->type === AssetOperationType::Withdrawal && $op->date->gt($withdrawal->date)));
            $available = $calculator->at($before, $valuations, $withdrawal->date)->value;

            if ((int) $withdrawal->amount > $available) {
                throw ValidationException::withMessages([
                    'amount' => sprintf('O resgate de %s em %s é maior que o valor do ativo na data (%s).',
                        MoneyFormatter::formatMinor((int) $withdrawal->amount), $withdrawal->date->format('d/m/Y'), MoneyFormatter::formatMinor($available)),
                ]);
            }
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
