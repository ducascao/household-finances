<?php

namespace App\Filament\Forms;

use App\Support\MoneyFormatter;
use Brick\Money\Money;
use Filament\Forms\Components\TextInput;
use Filament\Support\RawJs;
use InvalidArgumentException;

/**
 * Campo de valor no padrão brasileiro (1.234,56). O estado salvo é inteiro em centavos.
 */
class MoneyInput
{
    public static function make(string $name): TextInput
    {
        return TextInput::make($name)
            ->prefix('R$')
            ->mask(RawJs::make("\$money(\$input, ',', '.', 2)"))
            ->formatStateUsing(fn (mixed $state): ?string => match (true) {
                $state instanceof Money => self::toInput($state->getMinorAmount()->toInt()),
                is_int($state) => self::toInput($state),
                default => $state,
            })
            ->dehydrateStateUsing(fn (mixed $state): ?int => self::toMinor($state))
            ->rule(fn (): \Closure => function (string $attribute, mixed $value, \Closure $fail): void {
                try {
                    self::toMinor($value);
                } catch (InvalidArgumentException) {
                    $fail('Valor inválido.');
                }
            });
    }

    private static function toInput(int $minor): string
    {
        return ltrim(str_replace('R$ ', '', MoneyFormatter::formatMinor($minor)));
    }

    private static function toMinor(mixed $state): ?int
    {
        if ($state === null || $state === '') {
            return null;
        }

        return MoneyFormatter::parseToMinor(is_int($state) || is_float($state) ? $state : (string) $state);
    }
}
