<?php

namespace App\Domain\Currency;

use App\Contracts\ExchangeRateProvider;
use App\Models\Account;
use App\Models\ExchangeRate;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Brick\Money\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Câmbio para reais: a taxa da data ou, sem ela (fim de semana, feriado, atraso), a última anterior disponível.
 */
class ExchangeRates
{
    public const BRL = 'BRL';

    /** @var array<string, BigDecimal|null> */
    private array $cache = [];

    public function __construct(
        private readonly ExchangeRateProvider $provider,
    ) {}

    /**
     * Reais por 1 unidade da moeda na data. BRL = 1. Null quando não há taxa até a data.
     */
    public function rateAt(string $currency, Carbon $date): ?BigDecimal
    {
        if ($currency === self::BRL) {
            return BigDecimal::one();
        }

        $key = $currency.'|'.$date->toDateString();

        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        $rate = ExchangeRate::query()
            ->where('currency', $currency)
            ->whereDate('date', '<=', $date)
            ->orderByDesc('date')
            ->value('rate');

        return $this->cache[$key] = $rate !== null ? BigDecimal::of((string) $rate) : null;
    }

    /**
     * @throws MissingExchangeRate
     */
    public function toBrl(Money $money, Carbon $date): Money
    {
        $currency = $money->getCurrency()->getCurrencyCode();
        $rate = $this->rateAt($currency, $date) ?? throw MissingExchangeRate::for($currency, $date);

        return Money::of($money->getAmount()->multipliedBy($rate)->toScale(2, RoundingMode::HalfUp), self::BRL);
    }

    /**
     * Converte centavos da moeda em centavos de real.
     *
     * @throws MissingExchangeRate
     */
    public function minorToBrl(int $minor, string $currency, Carbon $date): int
    {
        return $this->toBrl(Money::ofMinor($minor, $currency), $date)->getMinorAmount()->toInt();
    }

    /**
     * Busca e grava o câmbio das moedas usadas nas contas (ou das informadas). Repetir não duplica;
     * falha é registrada no log.
     *
     * @param  list<string>|null  $currencies
     * @return int taxas gravadas
     */
    public function fetch(Carbon $from, Carbon $to, ?array $currencies = null): int
    {
        $currencies ??= Account::withoutGlobalScopes()->where('currency', '!=', self::BRL)->distinct()->pluck('currency')->all();
        $stored = 0;

        foreach ($currencies as $currency) {
            try {
                $rates = $this->provider->rates($currency, $from, $to);
            } catch (Throwable $e) {
                Log::error("Falha ao buscar o câmbio de {$currency}: ".$e->getMessage());

                continue;
            }

            foreach ($rates as $date => $rate) {
                ExchangeRate::updateOrCreate(['currency' => $currency, 'date' => $date], ['rate' => $rate]);
                $stored++;
            }
        }

        $this->cache = [];

        return $stored;
    }
}
