<?php

namespace App\Domain\Debts;

use Illuminate\Support\Carbon;

/**
 * Linha da tabela de amortização (centavos).
 */
final readonly class ScheduleRow
{
    public function __construct(
        public int $number,
        public Carbon $dueDate,
        public int $amortization,
        public int $interest,
        public int $balanceAfter,
    ) {}

    public function total(): int
    {
        return $this->amortization + $this->interest;
    }
}
