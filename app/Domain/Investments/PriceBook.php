<?php

namespace App\Domain\Investments;

use App\Contracts\QuoteProvider;
use App\Enums\PriceSource;
use App\Models\Asset;
use App\Models\AssetPrice;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

/**
 * Cotações dos ativos: busca diária na API, ajuste manual e última cotação disponível.
 * Na mesma data, a cotação manual prevalece sobre a automática.
 */
class PriceBook
{
    public function __construct(
        private readonly QuoteProvider $provider,
    ) {}

    /**
     * Busca as cotações de todos os ativos (todos os lares). Falha da API não interrompe: a posição
     * segue usando a última cotação gravada.
     *
     * @return array{updated: int, missing: list<string>}
     */
    public function fetchAll(): array
    {
        $assets = Asset::withoutGlobalScopes()->whereNotNull('ticker')->get();

        if ($assets->isEmpty()) {
            return ['updated' => 0, 'missing' => []];
        }

        try {
            $quotes = $this->provider->latest($assets->pluck('ticker')->unique()->values()->all());
        } catch (Throwable $e) {
            Log::error('Falha ao buscar cotações: '.$e->getMessage());
            $quotes = [];
        }

        $updated = 0;

        foreach ($assets as $asset) {
            $quote = $quotes[$asset->ticker] ?? null;

            if ($quote === null) {
                continue;
            }

            $this->store($asset, $quote->date, $quote->price, PriceSource::Api);
            $updated++;
        }

        $missing = $assets->pluck('ticker')->unique()->diff(array_keys($quotes))->values()->all();

        return ['updated' => $updated, 'missing' => $missing];
    }

    public function setManual(User $actor, Asset $asset, Carbon $date, string $price): AssetPrice
    {
        if (! $actor->can('update', $asset)) {
            throw ValidationException::withMessages(['price' => 'Sem acesso a este ativo.']);
        }

        try {
            $value = Quantity::parse($price);
        } catch (InvalidArgumentException) {
            $value = null;
        }

        if ($value === null || ! BigDecimal::of($value)->isPositive()) {
            throw ValidationException::withMessages(['price' => 'Informe uma cotação maior que zero.']);
        }

        if ($date->isFuture()) {
            throw ValidationException::withMessages(['date' => 'A data da cotação não pode ser futura.']);
        }

        return $this->store($asset, $date, $value, PriceSource::Manual);
    }

    /**
     * Última cotação de cada ativo até a data informada (padrão: qualquer data). Manual prevalece na mesma data.
     *
     * @param  list<int>  $assetIds
     * @return Collection<int, AssetPrice> por asset_id
     */
    public function latestFor(array $assetIds, ?Carbon $until = null): Collection
    {
        if ($assetIds === []) {
            return collect();
        }

        $ids = implode(',', array_map('intval', $assetIds));
        $until = $until !== null ? " and date <= '".$until->toDateString()."'" : '';

        return AssetPrice::withoutGlobalScopes()
            ->fromRaw("(select distinct on (asset_id) * from asset_prices where asset_id in ({$ids}){$until}
                order by asset_id, date desc, (source = 'manual') desc, id desc) as asset_prices")
            ->get()
            ->keyBy('asset_id');
    }

    private function store(Asset $asset, Carbon $date, string $price, PriceSource $source): AssetPrice
    {
        $record = AssetPrice::withoutGlobalScopes()
            ->where('asset_id', $asset->id)
            ->whereDate('date', $date)
            ->where('source', $source->value)
            ->first() ?? new AssetPrice(['asset_id' => $asset->id, 'date' => $date->copy()->startOfDay(), 'source' => $source]);

        $record->household_id = $asset->household_id;
        $record->price = $price;
        $record->save();

        return $record;
    }
}
