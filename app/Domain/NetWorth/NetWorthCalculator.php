<?php

namespace App\Domain\NetWorth;

use App\Domain\Currency\ExchangeRates;
use App\Domain\Currency\MissingExchangeRate;
use App\Domain\Goods\GoodValue;
use App\Domain\Investments\AssetValuation;
use App\Domain\Investments\PriceBook;
use App\Enums\AccountType;
use App\Enums\TransactionStatus;
use App\Models\Account;
use App\Models\Asset;
use App\Models\AssetOperation;
use App\Models\Debt;
use App\Models\DebtAdjustment;
use App\Models\DebtInstallment;
use App\Models\DebtPrepayment;
use App\Models\ManualValuation;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Patrimônio líquido numa data (em reais) = contas + investimentos + bens − dívidas.
 *
 * - Contas: saldo inicial + lançamentos pagos com data até a data (contas em outra moeda pelo câmbio da data).
 *   Conta com data do saldo inicial: só os pagos depois dela; antes dela a conta fica de fora (sem saldo confiável).
 *   Cartões com saldo negativo contam como dívida (fatura em aberto).
 * - Investimentos: valor de cada ativo na data (AssetValuation), convertido pelo câmbio da data.
 * - Bens: valor de cada bem na data (GoodValue: última avaliação até a data, fora antes da compra e depois da venda).
 * - Dívidas: principal + correção (TR) − amortização das parcelas pagas até a data − amortizações extraordinárias
 *   e ajustes de saldo até a data,
 *   a partir de um mês antes do 1º vencimento (quando o dinheiro foi liberado).
 *
 * Tudo depende só do que aconteceu até a data, então recalcular um mês passado repete o valor.
 * Não usa escopos globais: o escopo (pessoa ou lar) define as contas consideradas.
 */
class NetWorthCalculator
{
    public function __construct(
        private readonly ExchangeRates $rates,
        private readonly AssetValuation $valuation,
        private readonly PriceBook $prices,
    ) {}

    public function at(NetWorthScope $scope, Carbon $date): NetWorthBreakdown
    {
        $date = $date->copy()->startOfDay();
        $accounts = $scope->accounts();
        $breakdown = new NetWorthBreakdown($date);

        $this->accountBalances($accounts, $date, $breakdown);
        $this->investments($accounts, $date, $breakdown);
        $this->goods($scope, $date, $breakdown);
        $this->debts($accounts, $date, $breakdown);

        return $breakdown;
    }

    /**
     * @param  Collection<int, Account>  $accounts
     */
    private function accountBalances(Collection $accounts, Carbon $date, NetWorthBreakdown $breakdown): void
    {
        $sums = DB::table('transactions')
            ->join('accounts', 'accounts.id', '=', 'transactions.account_id')
            ->whereIn('transactions.account_id', $accounts->modelKeys())
            ->where('transactions.status', TransactionStatus::Paid->value)
            ->whereDate('transactions.date', '<=', $date)
            ->where(fn ($query) => $query->whereNull('accounts.balance_date')->orWhereColumn('transactions.date', '>', 'accounts.balance_date'))
            ->groupBy('transactions.account_id')
            ->selectRaw('transactions.account_id, sum(transactions.amount) as total')
            ->pluck('total', 'account_id');

        foreach ($accounts as $account) {
            if ($account->balance_date !== null && $date->lt($account->balance_date)) {
                continue;
            }

            $balance = $account->initial_balance->getMinorAmount()->toInt() + (int) ($sums[$account->id] ?? 0);

            try {
                $brl = $this->toBrl($balance, $account->currency, $date);
            } catch (MissingExchangeRate) {
                $breakdown->missing[] = $account->name;

                continue;
            }

            if ($account->type === AccountType::CreditCard) {
                if ($brl < 0) {
                    $breakdown->debtItems['Cartão '.$account->name] = -$brl;
                } elseif ($brl > 0) {
                    $breakdown->accountItems[$account->name] = $brl;
                }

                continue;
            }

            if ($brl !== 0) {
                $breakdown->accountItems[$account->name] = $brl;
            }
        }
    }

    /**
     * @param  Collection<int, Account>  $accounts
     */
    private function investments(Collection $accounts, Carbon $date, NetWorthBreakdown $breakdown): void
    {
        $assets = Asset::withoutGlobalScopes()->whereIn('account_id', $accounts->modelKeys())->get();

        if ($assets->isEmpty()) {
            return;
        }

        $ids = $assets->modelKeys();
        $operations = AssetOperation::withoutGlobalScopes()->whereIn('asset_id', $ids)->get()->groupBy('asset_id');
        $valuations = ManualValuation::withoutGlobalScopes()->whereIn('asset_id', $ids)->get()->groupBy('asset_id');
        $prices = $this->prices->latestFor($ids, $date);

        foreach ($assets as $asset) {
            $value = $this->valuation->valueAt(
                $asset,
                $operations->get($asset->id, collect()),
                $valuations->get($asset->id, collect()),
                $prices->get($asset->id)?->price,
                $date,
            );

            if ($value === 0) {
                continue;
            }

            try {
                $label = $asset->type->label();
                $breakdown->investmentItems[$label] = ($breakdown->investmentItems[$label] ?? 0) + $this->toBrl($value, $asset->currency, $date);
            } catch (MissingExchangeRate) {
                $breakdown->missing[] = $asset->label();
            }
        }
    }

    private function goods(NetWorthScope $scope, Carbon $date, NetWorthBreakdown $breakdown): void
    {
        foreach ($scope->goods() as $good) {
            $value = GoodValue::at($good, $date);

            if ($value !== 0) {
                $breakdown->goodItems[$good->name] = ($breakdown->goodItems[$good->name] ?? 0) + $value;
            }
        }
    }

    /**
     * @param  Collection<int, Account>  $accounts
     */
    private function debts(Collection $accounts, Carbon $date, NetWorthBreakdown $breakdown): void
    {
        $debts = Debt::withoutGlobalScopes()->whereIn('payment_account_id', $accounts->modelKeys())->get();

        foreach ($debts as $debt) {
            if ($date->lt($debt->first_due_date->copy()->subMonthNoOverflow())) {
                continue;
            }

            $amortized = (int) DebtInstallment::withoutGlobalScopes()
                ->where('debt_installments.debt_id', $debt->id)
                ->join('transactions', 'transactions.id', '=', 'debt_installments.transaction_id')
                ->where('transactions.status', TransactionStatus::Paid->value)
                ->whereDate('transactions.date', '<=', $date)
                ->sum(DB::raw('debt_installments.amortization - debt_installments.correction'));

            $prepaid = (int) DebtPrepayment::withoutGlobalScopes()
                ->where('debt_id', $debt->id)
                ->whereDate('date', '<=', $date)
                ->sum('amount');

            $adjusted = (int) DebtAdjustment::withoutGlobalScopes()
                ->where('debt_id', $debt->id)
                ->whereDate('date', '<=', $date)
                ->sum('amount');

            $outstanding = max(0, $debt->principal - $amortized - $prepaid + $adjusted);

            if ($outstanding > 0) {
                $breakdown->debtItems[$debt->name] = $outstanding;
            }
        }
    }

    private function toBrl(int $minor, string $currency, Carbon $date): int
    {
        return $currency === ExchangeRates::BRL || $minor === 0 ? $minor : $this->rates->minorToBrl($minor, $currency, $date);
    }
}
