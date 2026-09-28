<?php

namespace App\Domain\Import\Parsers;

use Illuminate\Support\Carbon;

/**
 * Uma linha de extrato já normalizada: valor em centavos com o sinal da conta (débito negativo).
 */
final readonly class ParsedLine
{
    public function __construct(
        public int $lineNumber,
        public Carbon $date,
        public string $description,
        public int $amount,
    ) {}
}
