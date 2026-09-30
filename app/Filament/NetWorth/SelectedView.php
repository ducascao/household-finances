<?php

namespace App\Filament\NetWorth;

use App\Domain\NetWorth\NetWorthHistory;
use App\Models\User;

/**
 * Visão escolhida na página Patrimônio: "me" (o que eu vejo) ou "household" (só o compartilhado).
 */
trait SelectedView
{
    protected function isHousehold(): bool
    {
        return ($this->pageFilters['view'] ?? 'me') === 'household';
    }

    protected function history(): NetWorthHistory
    {
        return app(NetWorthHistory::class);
    }

    protected function viewer(): User
    {
        /** @var User */
        return auth()->user();
    }
}
