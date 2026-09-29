<?php

namespace App\Domain\Investments;

use App\Models\Account;
use App\Models\Asset;
use App\Models\ManualValuation;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Saldos informados de renda fixa e previdência (um por data; informar de novo na mesma data substitui).
 */
class ManageValuations
{
    public function save(User $actor, Asset $asset, Carbon $date, ?int $balance): ManualValuation
    {
        $this->ensureAccess($actor, $asset);

        if (! $asset->type->isValuedByBalance()) {
            throw ValidationException::withMessages(['balance' => 'Saldo informado só vale para renda fixa e previdência.']);
        }

        if ($balance === null || $balance < 0) {
            throw ValidationException::withMessages(['balance' => 'Informe o saldo (zero ou mais).']);
        }

        if ($date->isFuture()) {
            throw ValidationException::withMessages(['date' => 'A data do saldo não pode ser futura.']);
        }

        $valuation = ManualValuation::withoutGlobalScopes()->where('asset_id', $asset->id)->whereDate('date', $date)->first()
            ?? new ManualValuation(['asset_id' => $asset->id, 'date' => $date->copy()->startOfDay()]);
        $valuation->household_id = $asset->household_id;
        $valuation->balance = $balance;
        $valuation->save();

        return $valuation;
    }

    public function delete(User $actor, ManualValuation $valuation): void
    {
        $this->ensureAccess($actor, Asset::withoutGlobalScopes()->findOrFail($valuation->asset_id));
        $valuation->delete();
    }

    private function ensureAccess(User $actor, Asset $asset): void
    {
        $account = Account::withoutGlobalScopes()->find($asset->account_id);

        if ($account === null || ! $account->isVisibleTo($actor)) {
            throw ValidationException::withMessages(['asset' => 'Sem acesso a este ativo.']);
        }
    }
}
