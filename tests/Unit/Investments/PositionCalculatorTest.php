<?php

use App\Domain\Investments\InvalidPosition;
use App\Domain\Investments\PositionCalculator;
use App\Domain\Investments\Quantity;
use App\Enums\AssetOperationType;
use App\Models\AssetOperation;
use Illuminate\Support\Carbon;

function op(int $id, string $date, AssetOperationType $type, ?string $quantity = null, ?string $price = null, int $fees = 0, ?string $factor = null): AssetOperation
{
    $operation = new AssetOperation;
    $operation->id = $id;
    $operation->type = $type;
    $operation->date = Carbon::parse($date);
    $operation->quantity = $quantity;
    $operation->unit_price = $price;
    $operation->fees = $fees;
    $operation->factor = $factor;

    return $operation;
}

function position(array $operations): array
{
    $p = (new PositionCalculator)->calculate($operations);

    return [(string) $p->quantity, (string) $p->averagePrice, (string) $p->totalCost];
}

it('preço médio correto em compras, venda parcial, desdobramento e grupamento', function () {
    $ops = [
        // 100 × 10,00 + 5,00 de taxa = 1.005,00 → PM 10,05
        op(1, '2026-01-10', AssetOperationType::Buy, '100', '10', 500),
        // + 50 × 13,00 + 2,50 = 652,50 → custo 1.657,50 / 150 = PM 11,05
        op(2, '2026-02-10', AssetOperationType::Buy, '50', '13', 250),
        // venda de 60: PM não muda; custo = 90 × 11,05 = 994,50
        op(3, '2026-03-10', AssetOperationType::Sell, '60', '15', 300),
    ];
    expect(position($ops))->toBe(['90.00000000', '11.05000000', '994.50000000']);

    // desdobramento 1→2: 180 ações, PM 5,525, custo igual
    $ops[] = op(4, '2026-04-10', AssetOperationType::Split, factor: '2');
    expect(position($ops))->toBe(['180.00000000', '5.52500000', '994.50000000']);

    // + 20 × 6,00 + 1,00 = 121,00 → custo 1.115,50 / 200 = PM 5,5775
    $ops[] = op(5, '2026-05-10', AssetOperationType::Buy, '20', '6', 100);
    expect(position($ops))->toBe(['200.00000000', '5.57750000', '1115.50000000']);

    // grupamento 10→1: 20 ações, PM 55,775, custo igual
    $ops[] = op(6, '2026-06-10', AssetOperationType::ReverseSplit, factor: '10');
    expect(position($ops))->toBe(['20.00000000', '55.77500000', '1115.50000000']);
});

it('ordena por data, não pela ordem de lançamento', function () {
    expect(position([
        op(2, '2026-02-10', AssetOperationType::Sell, '10', '20'),
        op(1, '2026-01-10', AssetOperationType::Buy, '10', '10'),
    ]))->toBe(['0.00000000', '0.00000000', '0.00000000']);
});

it('zerar a posição zera o custo e a compra seguinte recomeça o PM', function () {
    expect(position([
        op(1, '2026-01-10', AssetOperationType::Buy, '10', '10'),
        op(2, '2026-01-20', AssetOperationType::Sell, '10', '12'),
        op(3, '2026-02-10', AssetOperationType::Buy, '5', '20'),
    ]))->toBe(['5.00000000', '20.00000000', '100.00000000']);
});

it('grupamento com quantidade fracionária', function () {
    expect(position([
        op(1, '2026-01-10', AssetOperationType::Buy, '105', '1'),
        op(2, '2026-02-10', AssetOperationType::ReverseSplit, factor: '10'),
    ]))->toBe(['10.50000000', '10.00000000', '105.00000000']);
});

it('recusa venda maior que a posição na data', function () {
    position([
        op(1, '2026-01-10', AssetOperationType::Buy, '10', '10'),
        op(2, '2026-01-05', AssetOperationType::Sell, '5', '10'),
    ]);
})->throws(InvalidPosition::class, 'A venda de 5 em 05/01/2026 é maior que a posição na data (0).');

it('lê e formata quantidades no padrão brasileiro', function () {
    expect(Quantity::parse('1.234,5'))->toBe('1234.50000000')
        ->and(Quantity::parse('0.12345678'))->toBe('0.12345678')
        ->and(Quantity::format('1234.50000000'))->toBe('1.234,5')
        ->and(Quantity::format('55.775', 2))->toBe('55,775');
});

it('registra o resultado realizado de cada venda, com taxas e depois de desdobramento', function () {
    $history = (new PositionCalculator)->history([
        // 100 × 10 + 5 = 1.005 → PM 10,05
        op(1, '2026-01-10', AssetOperationType::Buy, '100', '10', 500),
        // venda de 40 × 12 − 2 = 478; custo 40 × 10,05 = 402 → +76,00
        op(2, '2026-02-10', AssetOperationType::Sell, '40', '12', 200),
        // desdobramento 1→2: 120 ações, PM 5,025
        op(3, '2026-03-10', AssetOperationType::Split, factor: '2'),
        // venda de 20 × 4,50 − 1 = 89; custo 20 × 5,025 = 100,50 → −11,50
        op(4, '2026-04-10', AssetOperationType::Sell, '20', '4.5', 100),
    ]);

    expect(array_map(fn ($sale) => [(string) $sale->proceeds, (string) $sale->costBasis, $sale->resultMinor()], $history['sales']))->toBe([
        ['478.00000000', '402.00000000', 7600],
        ['89.00000000', '100.50000000', -1150],
    ])->and((string) $history['position']->quantity)->toBe('100.00000000');
});
