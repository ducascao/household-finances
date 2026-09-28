<?php

use App\Support\MoneyFormatter;
use Brick\Money\Money;

it('formata valores em reais', function (int $minor, string $expected) {
    expect(MoneyFormatter::formatMinor($minor))->toBe($expected);
})->with([
    [123456, 'R$ 1.234,56'],
    [5, 'R$ 0,05'],
    [0, 'R$ 0,00'],
    [-123456, '-R$ 1.234,56'],
    [123456789012, 'R$ 1.234.567.890,12'],
]);

it('formata outras moedas com o símbolo delas', function () {
    expect(MoneyFormatter::format(Money::ofMinor(9999, 'USD')))->toBe('US$ 99,99');
});

it('lê valores digitados no padrão brasileiro', function (string $input, int $expected) {
    expect(MoneyFormatter::parseToMinor($input))->toBe($expected);
})->with([
    ['1.234,56', 123456],
    ['1234,56', 123456],
    ['1234,5', 123450],
    ['10', 1000],
    ['0,01', 1],
    ['-1.000,00', -100000],
    ['R$ 5,00', 500],
]);

it('rejeita valores mal formatados', function (string $input) {
    MoneyFormatter::parseToMinor($input);
})->with(['abc', '1,2,3', '1.23', ''])->throws(InvalidArgumentException::class);
