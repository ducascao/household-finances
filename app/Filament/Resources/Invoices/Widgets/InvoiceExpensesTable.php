<?php

namespace App\Filament\Resources\Invoices\Widgets;

use App\Domain\CreditCard\InvoiceCategoryBreakdown;
use App\Filament\Resources\Transactions\TransactionResource;
use App\Filament\Support\Sensitive;
use App\Models\CreditCard;
use App\Models\Invoice;
use App\Support\MoneyFormatter;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * Detalhe da fatura por categoria: principal, subcategorias, créditos e total (= total da fatura).
 * Clicar numa categoria abre os lançamentos do cartão nessa categoria e no mês da fatura.
 */
class InvoiceExpensesTable extends TableWidget
{
    public ?Invoice $record = null;

    private ?CreditCard $card = null;

    public function table(Table $table): Table
    {
        return $table
            ->heading('Detalhe por categoria')
            ->records(fn (): array => $this->rows())
            ->paginated(false)
            ->emptyStateHeading('Nenhuma compra nesta fatura')
            ->recordUrl(fn (array $record): ?string => $this->urlFor($record['category_id']))
            ->columns([
                TextColumn::make('name')
                    ->label('Categoria')
                    ->weight(fn (array $record): ?string => $record['is_root'] ? 'bold' : null),
                TextColumn::make('total')
                    ->extraAttributes(Sensitive::ATTRIBUTES, merge: true)
                    ->label('Valor')
                    ->alignEnd()
                    ->weight(fn (array $record): ?string => $record['is_root'] ? 'bold' : null)
                    ->formatStateUsing(fn (int $state): string => MoneyFormatter::formatMinor($state, $this->currency())),
                TextColumn::make('percent')
                    ->label('% dos gastos')
                    ->alignEnd()
                    ->formatStateUsing(fn (?float $state): string => $state === null ? '' : number_format($state, 1, ',', '.').'%'),
            ]);
    }

    /**
     * @return array<string, array{category_id: int|null, name: string, total: int, percent: float|null, is_root: bool}>
     */
    private function rows(): array
    {
        if ($this->record === null) {
            return [];
        }

        $breakdown = InvoiceCategoryBreakdown::of($this->record);
        $rows = [];

        foreach ($breakdown['categories'] as $root) {
            $rows['c'.$root['category']->id] = [
                'category_id' => $root['category']->id,
                'name' => $root['category']->name,
                'total' => $root['total'],
                'percent' => $root['percent'],
                'is_root' => true,
            ];

            foreach ($root['children'] as $child) {
                $rows['c'.$child['category']->id] = [
                    'category_id' => $child['category']->id,
                    'name' => '↳ '.$child['category']->name,
                    'total' => $child['total'],
                    'percent' => null,
                    'is_root' => false,
                ];
            }
        }

        if ($rows === []) {
            return [];
        }

        if ($breakdown['credits'] !== 0) {
            $rows['credits'] = ['category_id' => null, 'name' => 'Créditos', 'total' => $breakdown['credits'], 'percent' => null, 'is_root' => true];
        }

        $rows['total'] = ['category_id' => null, 'name' => 'Total da fatura', 'total' => $breakdown['total'], 'percent' => null, 'is_root' => true];

        return $rows;
    }

    private function urlFor(?int $categoryId): ?string
    {
        if ($categoryId === null || $this->record === null) {
            return null;
        }

        return TransactionResource::getUrl('index', [
            'filters' => [
                'account_id' => ['values' => [(string) $this->card()->account_id]],
                'category_id' => ['value' => (string) $categoryId],
                'competence' => ['value' => $this->record->reference_month->format('Y-m')],
            ],
        ]);
    }

    private function currency(): string
    {
        return $this->record === null ? 'BRL' : $this->card()->currency;
    }

    private function card(): CreditCard
    {
        /** @var Invoice $invoice */
        $invoice = $this->record;

        return $this->card ??= CreditCard::withoutGlobalScopes()->findOrFail($invoice->credit_card_id);
    }
}
