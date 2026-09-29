<?php

namespace App\Domain\Investments;

use App\Enums\AssetOperationType;
use App\Models\AssetOperation;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Calcula a posição percorrendo as operações em ordem (data, depois id).
 *
 * - Compra: custo += qtd × preço + taxas; PM = custo ÷ qtd.
 * - Venda: qtd diminui e o custo cai pelo PM (o PM não muda). Zerou a posição, zera o custo.
 * - Desdobramento 1→N: qtd × N e PM ÷ N. Grupamento N→1: qtd ÷ N e PM × N. O custo total não muda.
 */
class PositionCalculator
{
    public const SCALE = 8;

    /**
     * @param  iterable<AssetOperation>  $operations
     *
     * @throws InvalidPosition quando alguma venda deixaria a quantidade negativa
     */
    public function calculate(iterable $operations): Position
    {
        $sorted = collect($operations)->sortBy([
            fn (AssetOperation $a, AssetOperation $b): int => $a->date->toDateString() <=> $b->date->toDateString(),
            fn (AssetOperation $a, AssetOperation $b): int => ($a->id ?? PHP_INT_MAX) <=> ($b->id ?? PHP_INT_MAX),
        ]);

        $position = Position::empty();

        foreach ($sorted as $operation) {
            $position = $this->apply($position, $operation);
        }

        return $position;
    }

    public function apply(Position $position, AssetOperation $operation): Position
    {
        $quantity = BigDecimal::of($operation->quantity ?? '0');
        $price = BigDecimal::of($operation->unit_price ?? '0');
        $fees = BigDecimal::ofUnscaledValue($operation->fees, 2);
        $factor = BigDecimal::of($operation->factor ?? '1');

        return match ($operation->type) {
            AssetOperationType::Buy => $this->buy($position, $quantity, $price, $fees),
            AssetOperationType::Sell => $this->sell($position, $quantity, $operation),
            AssetOperationType::Split => new Position(
                $this->scale($position->quantity->multipliedBy($factor)),
                $this->divide($position->averagePrice, $factor),
                $position->totalCost,
            ),
            AssetOperationType::ReverseSplit => new Position(
                $this->divide($position->quantity, $factor),
                $this->scale($position->averagePrice->multipliedBy($factor)),
                $position->totalCost,
            ),
        };
    }

    private function buy(Position $position, BigDecimal $quantity, BigDecimal $price, BigDecimal $fees): Position
    {
        $newQuantity = $position->quantity->plus($quantity);
        $newCost = $position->totalCost->plus($quantity->multipliedBy($price))->plus($fees);

        return new Position($this->scale($newQuantity), $this->divide($newCost, $newQuantity), $this->scale($newCost));
    }

    private function sell(Position $position, BigDecimal $quantity, AssetOperation $operation): Position
    {
        $remaining = $position->quantity->minus($quantity);

        if ($remaining->isNegative()) {
            throw InvalidPosition::oversold($operation, $position->quantity);
        }

        if ($remaining->isZero()) {
            return Position::empty();
        }

        return new Position(
            $this->scale($remaining),
            $position->averagePrice,
            $this->scale($position->averagePrice->multipliedBy($remaining)),
        );
    }

    private function divide(BigDecimal $value, BigDecimal $by): BigDecimal
    {
        return $by->isZero() ? BigDecimal::zero()->toScale(self::SCALE) : $value->dividedBy($by, self::SCALE, RoundingMode::HalfUp);
    }

    private function scale(BigDecimal $value): BigDecimal
    {
        return $value->toScale(self::SCALE, RoundingMode::HalfUp);
    }
}
