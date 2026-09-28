<?php

namespace App\Domain\Transfers;

use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * As duas pernas de uma transferência, lidas sem o filtro de visibilidade.
 */
class TransferLegs
{
    public function __construct(
        public readonly Transaction $out,
        public readonly Transaction $in,
    ) {}

    public static function of(Transaction $leg): self
    {
        if ($leg->transfer_id === null) {
            throw ValidationException::withMessages(['transaction' => 'O lançamento não é uma transferência.']);
        }

        $legs = Transaction::withoutGlobalScopes()->where('transfer_id', $leg->transfer_id)->get();

        $out = $legs->first(fn (Transaction $t): bool => $t->amount->isNegative());
        $in = $legs->first(fn (Transaction $t): bool => ! $t->amount->isNegative());

        if ($legs->count() !== 2 || $out === null || $in === null) {
            throw ValidationException::withMessages(['transaction' => 'Transferência inconsistente.']);
        }

        return new self($out, $in);
    }

    /**
     * Só quem vê as duas contas altera ou exclui a transferência.
     */
    public function isManageableBy(User $user): bool
    {
        return $this->accounts()->every(fn (?Account $account): bool => $account !== null && $account->isVisibleTo($user));
    }

    public function ensureManageableBy(User $user): void
    {
        if (! $this->isManageableBy($user)) {
            throw ValidationException::withMessages([
                'transaction' => 'Só quem vê as duas contas pode alterar esta transferência.',
            ]);
        }
    }

    /**
     * @return Collection<int, Account|null>
     */
    public function accounts(): Collection
    {
        return collect([$this->out->account_id, $this->in->account_id])
            ->map(fn (int $id): ?Account => Account::withoutGlobalScopes()->find($id));
    }

    /**
     * @return list<Transaction>
     */
    public function all(): array
    {
        return [$this->out, $this->in];
    }
}
