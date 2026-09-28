<?php

namespace App\Filament\Monthly;

use Illuminate\Support\Carbon;

/**
 * Mês escolhido no filtro da página "Resumo do mês" (formato AAAA-MM), padrão: o mês corrente.
 */
trait SelectedMonth
{
    protected function month(): Carbon
    {
        $value = $this->pageFilters['month'] ?? null;

        if (is_string($value) && preg_match('/^\d{4}-\d{2}$/', $value) === 1) {
            return Carbon::createFromFormat('!Y-m', $value)?->startOfMonth() ?? today()->startOfMonth();
        }

        return today()->startOfMonth();
    }

    protected static function monthLabel(Carbon $month): string
    {
        return ucfirst($month->locale('pt_BR')->translatedFormat('F/Y'));
    }
}
