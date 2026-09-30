<?php

namespace App\Filament\Resources\Debts\Schemas;

use App\Domain\Debts\ManageDebts;
use App\Enums\AccountType;
use App\Enums\CategoryType;
use App\Enums\DebtSystem;
use App\Filament\Forms\MoneyInput;
use App\Models\Account;
use App\Models\Category;
use App\Support\MoneyFormatter;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

class DebtForm
{
    public static function configure(Schema $schema): Schema
    {
        $custom = fn (Get $get): bool => ($get('system') instanceof DebtSystem ? $get('system')->value : $get('system')) === DebtSystem::Custom->value;

        return $schema->components([
            Section::make('Dívida')
                ->columns(3)
                ->columnSpanFull()
                ->schema([
                    TextInput::make('name')->label('Nome')->placeholder('Financiamento do apartamento')->required()->maxLength(255),
                    TextInput::make('creditor')->label('Credor')->placeholder('Caixa, Banco X…')->required()->maxLength(255),
                    Select::make('system')->label('Sistema')->options(DebtSystem::options())->default(DebtSystem::Price->value)->live()->required(),
                    MoneyInput::make('principal')->label('Valor financiado')->live(onBlur: true)->required(),
                    TextInput::make('monthly_rate')->label('Juros ao mês (%)')->placeholder('0,95')->live(onBlur: true)->required(),
                    TextInput::make('installments_count')->label('Número de parcelas')->integer()->minValue(1)->maxValue(600)
                        ->live(onBlur: true)->hidden($custom)->required(fn (Get $get): bool => ! $custom($get)),
                    DatePicker::make('first_due_date')->label('1º vencimento')->displayFormat('d/m/Y')->native(false)->live()->required(),
                    Select::make('payment_account_id')->label('Conta de pagamento')
                        ->options(fn (): array => Account::query()->whereNull('archived_at')->where('currency', 'BRL')
                            ->whereIn('type', [AccountType::Checking->value, AccountType::Savings->value, AccountType::Cash->value])
                            ->orderBy('name')->pluck('name', 'id')->all())
                        ->helperText('As parcelas viram lançamentos previstos nesta conta.')
                        ->required(),
                    Select::make('category_id')->label('Categoria das parcelas')
                        ->options(fn (): array => Category::query()->where('type', CategoryType::Expense->value)->with('parent')->get()
                            ->mapWithKeys(fn (Category $category): array => [$category->id => $category->fullName()])->sort()->all())
                        ->searchable()
                        ->required(),
                    Textarea::make('notes')->label('Observações')->columnSpanFull(),
                ]),
            Section::make('Tabela informada')
                ->description('Copie do extrato do banco: vencimento, amortização e juros de cada parcela. A soma das amortizações precisa fechar o valor financiado.')
                ->visible($custom)
                ->columnSpanFull()
                ->schema([
                    Repeater::make('rows')
                        ->hiddenLabel()
                        ->columns(3)
                        ->addActionLabel('Adicionar parcela')
                        ->schema([
                            DatePicker::make('due_date')->label('Vencimento')->displayFormat('d/m/Y')->native(false)->required(),
                            MoneyInput::make('amortization')->label('Amortização')->required(),
                            MoneyInput::make('interest')->label('Juros')->default(0)->required(),
                        ]),
                ]),
            Section::make('Prévia')
                ->visible(fn (Get $get): bool => ! $custom($get))
                ->columnSpanFull()
                ->schema([
                    TextEntry::make('preview')->hiddenLabel()->html()->state(fn (Get $get): HtmlString => self::preview($get)),
                ]),
        ]);
    }

    private static function preview(Get $get): HtmlString
    {
        try {
            $rows = app(ManageDebts::class)->preview([
                'name' => 'prévia', 'creditor' => 'prévia', 'payment_account_id' => 0, 'category_id' => 0,
                'system' => $get('system') instanceof DebtSystem ? $get('system')->value : $get('system'),
                'principal' => self::minor($get('principal')),
                'monthly_rate' => $get('monthly_rate'),
                'installments_count' => $get('installments_count'),
                'first_due_date' => $get('first_due_date'),
            ]);
        } catch (ValidationException) {
            return new HtmlString('<span class="fc-label">Preencha valor, juros, parcelas e 1º vencimento para ver a tabela.</span>');
        } catch (Throwable $e) {
            return new HtmlString('<span class="fc-warning">'.e($e->getMessage()).'</span>');
        }

        $first = $rows[0];
        $last = end($rows);
        $interest = array_sum(array_map(fn ($row) => $row->interest, $rows));
        $total = array_sum(array_map(fn ($row) => $row->total(), $rows));
        $line = fn (string $label, string $value): string => '<div class="fc-row fc-text"><span>'.e($label).'</span><span class="fc-strong fc-num">'.e($value).'</span></div>';

        return new HtmlString('<div class="fc-stack" style="gap: 0.25rem; max-width: 28rem;">'
            .$line('1ª parcela ('.$first->dueDate->format('d/m/Y').')', MoneyFormatter::formatMinor($first->total()))
            .$line('Última parcela ('.$last->dueDate->format('d/m/Y').')', MoneyFormatter::formatMinor($last->total()))
            .$line('Total de juros', MoneyFormatter::formatMinor($interest))
            .$line('Total a pagar', MoneyFormatter::formatMinor($total))
            .'</div>');
    }

    private static function minor(mixed $value): ?int
    {
        try {
            return is_int($value) ? $value : ($value === null || $value === '' ? null : MoneyFormatter::parseToMinor((string) $value));
        } catch (InvalidArgumentException) {
            return null;
        }
    }
}
