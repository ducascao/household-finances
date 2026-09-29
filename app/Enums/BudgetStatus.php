<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum BudgetStatus: string
{
    use HasOptions;

    case Within = 'within';
    case Attention = 'attention';
    case Exceeded = 'exceeded';

    public const ATTENTION_PERCENT = 80;

    public function label(): string
    {
        return match ($this) {
            self::Within => 'Dentro',
            self::Attention => 'Atenção',
            self::Exceeded => 'Estourado',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Within => 'success',
            self::Attention => 'warning',
            self::Exceeded => 'danger',
        };
    }

    /**
     * Abaixo de 80%: dentro; de 80% a 100%: atenção; acima de 100%: estourado.
     */
    public static function fromPercent(float $percent): self
    {
        return match (true) {
            $percent > 100 => self::Exceeded,
            $percent >= self::ATTENTION_PERCENT => self::Attention,
            default => self::Within,
        };
    }
}
