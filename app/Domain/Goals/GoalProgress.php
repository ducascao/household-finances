<?php

namespace App\Domain\Goals;

use App\Domain\Accounts\AccountBalance;
use App\Domain\Currency\ExchangeRates;
use App\Domain\Currency\MissingExchangeRate;
use App\Domain\Investments\AssetValuation;
use App\Domain\Investments\PriceBook;
use App\Enums\GoalStatus;
use App\Models\Account;
use App\Models\Asset;
use App\Models\AssetOperation;
use App\Models\Goal;
use App\Models\ManualValuation;

/**
 * Progresso de uma meta hoje (centavos, reais).
 *
 * - progresso = saldo atual das contas vinculadas + valor dos investimentos vinculados (câmbio de hoje)
 * - falta = alvo − progresso
 * - meses restantes = do mês atual ao mês do prazo (mínimo 1)
 * - ritmo mensal = falta ÷ meses restantes, arredondado para cima
 * - situação: atingida; prazo vencido; no ritmo (progresso ≥ linha reta do início ao prazo); atrasada
 */
final class GoalProgress
{
    public readonly int $current;

    /** @var array<string, int> origem => valor */
    public readonly array $sources;

    /** @var list<string> */
    public readonly array $missing;

    public function __construct(public readonly Goal $goal)
    {
        $rates = app(ExchangeRates::class);
        $today = today();
        $sources = [];
        $missing = [];

        foreach (Account::withoutGlobalScopes()->whereIn('id', $goal->accounts()->withoutGlobalScopes()->pluck('accounts.id'))->get() as $account) {
            try {
                $sources['Conta '.$account->name] = $rates->minorToBrl(AccountBalance::of($account)->getMinorAmount()->toInt(), $account->currency, $today);
            } catch (MissingExchangeRate) {
                $missing[] = $account->name;
            }
        }

        $assets = Asset::withoutGlobalScopes()->whereIn('id', $goal->assets()->withoutGlobalScopes()->pluck('assets.id'))->get();

        if ($assets->isNotEmpty()) {
            $ids = $assets->modelKeys();
            $operations = AssetOperation::withoutGlobalScopes()->whereIn('asset_id', $ids)->get()->groupBy('asset_id');
            $valuations = ManualValuation::withoutGlobalScopes()->whereIn('asset_id', $ids)->get()->groupBy('asset_id');
            $prices = app(PriceBook::class)->latestFor($ids);

            foreach ($assets as $asset) {
                $value = app(AssetValuation::class)->valueAt($asset, $operations->get($asset->id, collect()), $valuations->get($asset->id, collect()), $prices->get($asset->id)?->price, $today);

                try {
                    $sources[$asset->label()] = $rates->minorToBrl($value, $asset->currency, $today);
                } catch (MissingExchangeRate) {
                    $missing[] = $asset->label();
                }
            }
        }

        $this->sources = $sources;
        $this->missing = $missing;
        $this->current = max(0, array_sum($sources));
    }

    public function remaining(): int
    {
        return max(0, $this->goal->target - $this->current);
    }

    public function percent(): float
    {
        return $this->goal->target > 0 ? round(min($this->current / $this->goal->target * 100, 999), 1) : 0.0;
    }

    public function monthsLeft(): int
    {
        $today = today();
        $months = ($this->goal->deadline->year * 12 + $this->goal->deadline->month) - ($today->year * 12 + $today->month);

        return max(1, $months);
    }

    /**
     * Quanto guardar por mês até o prazo (0 se já atingiu ou se o prazo passou).
     */
    public function monthlyPace(): int
    {
        if ($this->remaining() === 0 || $this->isExpired()) {
            return 0;
        }

        return (int) ceil($this->remaining() / $this->monthsLeft());
    }

    /**
     * Onde deveria estar hoje numa linha reta do início ao prazo.
     */
    public function expectedToday(): int
    {
        $total = max(1, (int) $this->goal->start_date->diffInDays($this->goal->deadline));
        $elapsed = min($total, max(0, (int) $this->goal->start_date->diffInDays(today(), false)));

        return (int) round($this->goal->target * $elapsed / $total);
    }

    public function isExpired(): bool
    {
        return $this->goal->deadline->lt(today());
    }

    public function status(): GoalStatus
    {
        return match (true) {
            $this->current >= $this->goal->target => GoalStatus::Achieved,
            $this->isExpired() => GoalStatus::Expired,
            $this->current >= $this->expectedToday() => GoalStatus::OnTrack,
            default => GoalStatus::Behind,
        };
    }
}
