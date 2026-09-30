<?php

namespace App\Domain\NetWorth;

use Illuminate\Support\Carbon;

/**
 * Patrimônio numa data, em centavos de real, com o detalhe de cada parte.
 */
final class NetWorthBreakdown
{
    /**
     * @param  array<string, int>  $accountItems  nome da conta => saldo
     * @param  array<string, int>  $investmentItems  tipo de ativo => valor
     * @param  array<string, int>  $debtItems  dívida ou cartão => saldo devedor
     * @param  list<string>  $missing  itens que ficaram de fora por falta de câmbio
     */
    public function __construct(
        public readonly Carbon $date,
        public array $accountItems = [],
        public array $investmentItems = [],
        public array $debtItems = [],
        public array $missing = [],
    ) {}

    public function accounts(): int
    {
        return array_sum($this->accountItems);
    }

    public function investments(): int
    {
        return array_sum($this->investmentItems);
    }

    public function debts(): int
    {
        return array_sum($this->debtItems);
    }

    public function netWorth(): int
    {
        return $this->accounts() + $this->investments() - $this->debts();
    }
}
