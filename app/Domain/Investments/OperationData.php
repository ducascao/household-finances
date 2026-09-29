<?php

namespace App\Domain\Investments;

use App\Enums\AssetOperationType;
use Brick\Math\BigDecimal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Valida os dados de uma operação. Quantidade, preço e fator aceitam "1.234,5" ou "1234.5"; taxas em centavos.
 */
class OperationData
{
    /**
     * @param  array<string, mixed>  $data
     * @return array{type: AssetOperationType, date: Carbon, quantity: string|null, unit_price: string|null, fees: int, factor: string|null, notes: string|null}
     */
    public static function resolve(array $data): array
    {
        $data['type'] = ($data['type'] ?? null) instanceof AssetOperationType ? $data['type']->value : ($data['type'] ?? null);

        /** @var array{type: string, date: string, notes?: string|null} $validated */
        $validated = Validator::make($data, [
            'type' => ['required', Rule::enum(AssetOperationType::class)],
            'date' => ['required', 'date'],
            'notes' => ['nullable', 'string'],
        ], attributes: ['type' => 'tipo', 'date' => 'data'])->validate();

        $type = AssetOperationType::from($validated['type']);

        try {
            $quantity = Quantity::parse($data['quantity'] ?? null);
            $price = Quantity::parse($data['unit_price'] ?? null);
            $factor = Quantity::parse($data['factor'] ?? null);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['quantity' => $e->getMessage()]);
        }

        $fees = (int) ($data['fees'] ?? 0);
        $positive = fn (?string $value): bool => $value !== null && BigDecimal::of($value)->isPositive();

        $errors = $type->isTrade()
            ? array_filter([
                'quantity' => $positive($quantity) ? null : 'Informe a quantidade.',
                'unit_price' => $positive($price) ? null : 'Informe o preço unitário.',
                'fees' => $fees >= 0 ? null : 'Taxas não podem ser negativas.',
            ])
            : array_filter([
                'factor' => $positive($factor) && ! BigDecimal::of((string) $factor)->isEqualTo(1)
                    ? null
                    : 'Informe a proporção (ex.: 2 para desdobramento 1→2, 10 para grupamento 10→1).',
            ]);

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [
            'type' => $type,
            'date' => Carbon::parse($validated['date'])->startOfDay(),
            'quantity' => $type->isTrade() ? $quantity : null,
            'unit_price' => $type->isTrade() ? $price : null,
            'fees' => $type->isTrade() ? $fees : 0,
            'factor' => $type->isTrade() ? null : $factor,
            'notes' => ($validated['notes'] ?? null) ?: null,
        ];
    }
}
