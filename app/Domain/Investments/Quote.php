<?php

namespace App\Domain\Investments;

use Illuminate\Support\Carbon;

final readonly class Quote
{
    public function __construct(
        public string $ticker,
        public string $price,
        public Carbon $date,
    ) {}
}
