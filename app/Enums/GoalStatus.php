<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum GoalStatus: string
{
    use HasOptions;

    case Achieved = 'achieved';
    case OnTrack = 'on_track';
    case Behind = 'behind';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Achieved => 'Atingida',
            self::OnTrack => 'No ritmo',
            self::Behind => 'Atrasada',
            self::Expired => 'Prazo vencido',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Achieved => 'success',
            self::OnTrack => 'info',
            self::Behind => 'warning',
            self::Expired => 'danger',
        };
    }
}
