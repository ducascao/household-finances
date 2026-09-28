<?php

namespace App\Enums\Concerns;

/**
 * Para enums com label(): opções value => label para selects.
 */
trait HasOptions
{
    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
