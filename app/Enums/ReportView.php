<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * De quem são os números do e-mail: só o compartilhado do lar ou tudo o que quem envia vê.
 */
enum ReportView: string
{
    use HasOptions;

    case Shared = 'shared';
    case Mine = 'mine';

    public function label(): string
    {
        return match ($this) {
            self::Shared => 'Só o compartilhado',
            self::Mine => 'Minha visão completa (inclui minhas contas pessoais)',
        };
    }
}
