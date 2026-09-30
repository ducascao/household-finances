<?php

namespace App\Domain\NetWorth;

use App\Enums\AccountVisibility;
use App\Models\Account;
use App\Models\Good;
use App\Models\Household;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * De quem é o patrimônio: de uma pessoa (contas compartilhadas + as pessoais dela) ou do lar
 * (só contas compartilhadas, para não expor contas pessoais de ninguém).
 */
final readonly class NetWorthScope
{
    private function __construct(
        public Household $household,
        public ?User $user,
    ) {}

    public static function person(User $user, ?Household $household = null): self
    {
        $household ??= Household::find($user->current_household_id) ?? throw new \InvalidArgumentException('Usuário sem lar.');

        return new self($household, $user);
    }

    public static function household(Household $household): self
    {
        return new self($household, null);
    }

    /**
     * @return Collection<int, Account>
     */
    public function accounts(): Collection
    {
        return Account::withoutGlobalScopes()
            ->where('household_id', $this->household->id)
            ->where(fn ($query) => $query
                ->where('visibility', AccountVisibility::Shared->value)
                ->when($this->user !== null, fn ($query) => $query->orWhere('owner_id', $this->user?->id)))
            ->get();
    }

    /**
     * Bens com a mesma regra das contas: compartilhados e, na visão da pessoa, também os dela.
     *
     * @return Collection<int, Good>
     */
    public function goods(): Collection
    {
        return Good::withoutGlobalScopes()
            ->where('household_id', $this->household->id)
            ->where(fn ($query) => $query
                ->where('visibility', AccountVisibility::Shared->value)
                ->when($this->user !== null, fn ($query) => $query->orWhere('owner_id', $this->user?->id)))
            ->get();
    }
}
