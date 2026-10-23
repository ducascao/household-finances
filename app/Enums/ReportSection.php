<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Partes que podem ir no e-mail de resumo.
 */
enum ReportSection: string
{
    use HasOptions;

    case MonthlySummary = 'monthly_summary';
    case NetWorth = 'net_worth';
    case Portfolio = 'portfolio';

    public function label(): string
    {
        return match ($this) {
            self::MonthlySummary => 'Resumo do mês',
            self::NetWorth => 'Patrimônio',
            self::Portfolio => 'Ativos (Carteira)',
        };
    }
}
