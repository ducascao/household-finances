<?php

namespace App\Domain\Reports;

use App\Models\Category;

/**
 * Agrupa totais por categoria na categoria principal, com as subcategorias abaixo.
 * Usado no resumo do mês e no detalhe da fatura do cartão.
 */
class CategoryTotals
{
    /**
     * @param  iterable<int|string, int|string>  $totals  centavos (positivos = gasto) por id de categoria
     * @return list<array{category: Category, total: int, percent: float, children: list<array{category: Category, total: int}>}>
     */
    public static function group(iterable $totals): array
    {
        $totals = collect($totals)->map(fn (int|string $total): int => (int) $total);
        $categories = Category::withoutGlobalScopes()->whereKey($totals->keys())->with('parent')->get()->keyBy('id');
        $grandTotal = (int) $totals->sum();
        $roots = [];

        foreach ($totals as $categoryId => $total) {
            $category = $categories->get($categoryId);

            if ($category === null) {
                continue;
            }

            $root = $category->parent ?? $category;

            $roots[$root->id] ??= ['category' => $root, 'total' => 0, 'percent' => 0.0, 'children' => []];
            $roots[$root->id]['total'] += $total;

            if ($category->parent !== null) {
                $roots[$root->id]['children'][] = ['category' => $category, 'total' => $total];
            }
        }

        foreach ($roots as &$root) {
            $root['percent'] = $grandTotal > 0 ? round($root['total'] / $grandTotal * 100, 1) : 0.0;
            usort($root['children'], fn (array $a, array $b): int => $b['total'] <=> $a['total']);
        }
        unset($root);

        usort($roots, fn (array $a, array $b): int => $b['total'] <=> $a['total']);

        return $roots;
    }
}
