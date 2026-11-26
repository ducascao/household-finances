<?php

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;

it('starts the calendar week on sunday', function (string $component) {
    expect($component::make('date')->getFirstDayOfWeek())->toBe(7);
})->with([DatePicker::class, DateTimePicker::class]);
