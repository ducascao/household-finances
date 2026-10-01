<?php

namespace App\Domain\Debts;

use App\Enums\DebtSystem;
use Brick\Math\BigDecimal;
use Illuminate\Support\Carbon;

/**
 * Condições para calcular as parcelas: sistema, juros, correção pela TR e encargos.
 */
final readonly class DebtTerms
{
    /**
     * @param  string  $monthlyRate  juros em % ao mês
     * @param  (\Closure(Carbon): BigDecimal)|null  $trFor  correção do saldo (fração, ex.: 0.001661) para a parcela com aquele vencimento
     * @param  string  $insuranceRate  seguro proporcional ao saldo, em % ao mês sobre o saldo corrigido
     * @param  int  $monthlyFee  encargos fixos por parcela (centavos)
     */
    public function __construct(
        public DebtSystem $system,
        public string $monthlyRate,
        public ?\Closure $trFor = null,
        public string $insuranceRate = '0',
        public int $monthlyFee = 0,
    ) {}
}
