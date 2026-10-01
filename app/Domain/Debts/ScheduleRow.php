<?php

namespace App\Domain\Debts;

use Illuminate\Support\Carbon;

/**
 * Linha da tabela de amortização (centavos). correction = correção do saldo (TR) aplicada antes da parcela;
 * charges = seguros e taxas cobrados junto (não abatem o saldo).
 */
final readonly class ScheduleRow
{
    public function __construct(
        public int $number,
        public Carbon $dueDate,
        public int $amortization,
        public int $interest,
        public int $balanceAfter,
        public int $correction = 0,
        public int $charges = 0,
    ) {}

    public function total(): int
    {
        return $this->amortization + $this->interest + $this->charges;
    }
}
