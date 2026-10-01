<?php

namespace App\Filament\Resources\Debts\Schemas;

use App\Domain\Debts\ManageDebts;
use App\Domain\Debts\ReferenceRate;
use App\Domain\Investments\Quantity;
use App\Enums\AccountType;
use App\Enums\CategoryType;
use App\Enums\DebtSystem;
use App\Enums\RatePeriod;
use App\Filament\Forms\MoneyInput;
use App\Models\Account;
use App\Models\Category;
use App\Support\MoneyFormatter;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
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
                    TextInput::make('rate')->label('Juros (%)')->placeholder('8,25')->live(onBlur: true)->required(),
                    Select::make('rate_period')->label('A taxa é')->options(RatePeriod::options())->default(RatePeriod::Monthly->value)->live()->required()
                        ->helperText('No contrato, use a taxa efetiva de juros (não o CET, que inclui seguros e tarifas).'),
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
            Section::make('Correção e encargos')
                ->description('Financiamento imobiliário: correção do saldo pela TR e o que é cobrado junto com a parcela.')
                ->visible(fn (Get $get): bool => ! $custom($get))
                ->columns(3)
                ->columnSpanFull()
                ->schema([
                    Toggle::make('tr_correction')->label('Saldo corrigido pela TR')->live()
                        ->helperText('Em cada vencimento, o saldo é corrigido pela TR do mês anterior (Banco Central).'),
                    TextInput::make('insurance_rate')->label('Seguro sobre o saldo (% ao mês)')->placeholder('0,0150')->live(onBlur: true)
                        ->helperText('Seguro de morte e invalidez (MIP), quando cobrado sobre o saldo.'),
                    MoneyInput::make('monthly_fee')->label('Encargos fixos por parcela')->live(onBlur: true)
                        ->helperText('Seguro do imóvel (DFI), taxa de administração.'),
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
                'rate' => $get('rate'),
                'rate_period' => $get('rate_period'),
                'tr_correction' => (bool) $get('tr_correction'),
                'insurance_rate' => $get('insurance_rate'),
                'monthly_fee' => self::minor($get('monthly_fee')) ?? 0,
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
        $detail = fn (string $label, int $value): string => '<div class="fc-row fc-label"><span>&nbsp;&nbsp;'.e($label).'</span><span class="fc-num">'.e(MoneyFormatter::formatMinor($value)).'</span></div>';
        $period = RatePeriod::tryFrom((string) ($get('rate_period') instanceof RatePeriod ? $get('rate_period')->value : $get('rate_period'))) ?? RatePeriod::Monthly;
        $tr = (bool) $get('tr_correction');
        $notes = [];

        if ($period !== RatePeriod::Monthly) {
            try {
                $notes[] = 'Juros equivalentes: '.number_format((float) $period->toMonthly(Quantity::parse($get('rate'))), 6, ',', '.').'% ao mês';
            } catch (InvalidArgumentException) {
            }
        }

        if ($tr) {
            $latest = app(ReferenceRate::class)->latest();
            $notes[] = $latest !== null
                ? 'TR da 1ª parcela: '.Quantity::format(app(ReferenceRate::class)->percentFor($first->dueDate), 4).'% (parcelas futuras usam a última TR conhecida, de '.$latest->date->format('d/m/Y').')'
                : 'Ainda não há TR carregada: rode a atualização da TR (README) para a prévia considerar a correção.';
        }

        return new HtmlString('<div class="fc-stack" style="gap: 0.25rem; max-width: 32rem;">'
            .$line('1ª parcela ('.$first->dueDate->format('d/m/Y').')', MoneyFormatter::formatMinor($first->total()))
            .($tr ? $detail('correção do saldo (TR)', $first->correction) : '')
            .$detail('amortização', $first->amortization)
            .$detail('juros', $first->interest)
            .($first->charges > 0 ? $detail('seguros e encargos', $first->charges) : '')
            .$line('Última parcela ('.$last->dueDate->format('d/m/Y').')', MoneyFormatter::formatMinor($last->total()))
            .$line('Total de juros', MoneyFormatter::formatMinor($interest))
            .$line('Total a pagar', MoneyFormatter::formatMinor($total))
            .implode('', array_map(fn (string $note): string => '<div class="fc-label">'.e($note).'</div>', $notes))
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
