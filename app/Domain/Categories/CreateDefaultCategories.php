<?php

namespace App\Domain\Categories;

use App\Enums\CategoryType;
use App\Models\Category;
use App\Models\Household;

class CreateDefaultCategories
{
    /**
     * Categorias principais => [cor, subcategorias].
     *
     * @var array<string, array<string, array{0: string, 1: list<string>}>>
     */
    public const DEFAULTS = [
        CategoryType::Income->value => [
            'Salário' => ['#16a34a', []],
            'Rendimentos' => ['#0d9488', ['Dividendos', 'JCP', 'Rendimentos de FII']],
            'Outras receitas' => ['#65a30d', []],
        ],
        CategoryType::Expense->value => [
            'Moradia' => ['#ea580c', ['Aluguel', 'Condomínio', 'Luz', 'Água', 'Gás', 'Internet', 'Manutenção']],
            'Alimentação' => ['#dc2626', ['Mercado', 'Restaurante', 'Delivery']],
            'Transporte' => ['#2563eb', ['Combustível', 'Transporte público', 'Aplicativo', 'Manutenção do carro']],
            'Saúde' => ['#db2777', ['Plano de saúde', 'Farmácia', 'Consultas']],
            'Educação' => ['#7c3aed', ['Cursos', 'Livros']],
            'Lazer' => ['#0891b2', ['Viagens', 'Assinaturas']],
            'Compras' => ['#ca8a04', ['Roupas', 'Casa', 'Eletrônicos']],
            'Serviços' => ['#4f46e5', ['Telefone', 'Serviços domésticos']],
            'Impostos e taxas' => ['#57534e', ['Tarifas bancárias', 'IPTU', 'IPVA']],
            'Outras despesas' => ['#6b7280', []],
        ],
    ];

    public function execute(Household $household): void
    {
        foreach (self::DEFAULTS as $type => $roots) {
            foreach ($roots as $name => [$color, $children]) {
                $parent = $this->create($household, $name, $type, $color);

                foreach ($children as $child) {
                    $this->create($household, $child, $type, $color, $parent);
                }
            }
        }
    }

    private function create(Household $household, string $name, string $type, string $color, ?Category $parent = null): Category
    {
        $category = new Category([
            'name' => $name,
            'type' => $type,
            'color' => $color,
            'parent_id' => $parent?->id,
        ]);
        $category->household_id = $household->id;
        $category->save();

        return $category;
    }
}
