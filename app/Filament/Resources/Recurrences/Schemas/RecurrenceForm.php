<?php

namespace App\Filament\Resources\Recurrences\Schemas;

use App\Enums\RecurrenceFrequency;
use App\Filament\Forms\MoneyInput;
use App\Filament\Resources\Transactions\Schemas\TransactionForm;
use App\Models\Account;
use App\Models\Recurrence;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;

class RecurrenceForm
{
    public static function configure(Schema $schema): Schema
    {
        $frequency = fn (Get $get): ?RecurrenceFrequency => RecurrenceFrequency::tryFrom(
            ($value = $get('frequency')) instanceof RecurrenceFrequency ? $value->value : (string) $value,
        );

        return $schema
            ->components([
                Section::make('Lançamento')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('description')
                            ->label('Descrição')
                            ->required()
                            ->maxLength(255),
                        Select::make('account_id')
                            ->label('Conta')
                            ->options(fn (?Recurrence $record): array => self::accountOptions($record))
                            ->searchable()
                            ->required(),
                        Select::make('category_id')
                            ->label('Categoria')
                            ->options(fn (): array => TransactionForm::categoryOptions())
                            ->helperText('Despesa sai negativa e receita entra positiva.')
                            ->searchable()
                            ->required(),
                        MoneyInput::make('amount')
                            ->label('Valor')
                            ->required(),
                        Toggle::make('amount_is_estimate')
                            ->label('Valor estimado')
                            ->helperText('Ex.: conta de luz. O valor real é informado ao pagar.'),
                        Select::make('paid_by')
                            ->label('Pago por')
                            ->options(fn (): array => TransactionForm::memberOptions())
                            ->default(fn (): ?int => Auth::id())
                            ->required(),
                        TagsInput::make('tags')
                            ->label('Tags'),
                        Textarea::make('notes')
                            ->label('Observações')
                            ->columnSpanFull(),
                    ]),
                Section::make('Repetição')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        Select::make('frequency')
                            ->label('Frequência')
                            ->options(RecurrenceFrequency::options())
                            ->default(RecurrenceFrequency::Monthly->value)
                            ->live()
                            ->required(),
                        TextInput::make('interval_months')
                            ->label('A cada quantos meses')
                            ->integer()
                            ->minValue(2)
                            ->maxValue(60)
                            ->visible(fn (Get $get): bool => $frequency($get) === RecurrenceFrequency::EveryNMonths)
                            ->required(fn (Get $get): bool => $frequency($get) === RecurrenceFrequency::EveryNMonths),
                        TextInput::make('day_of_month')
                            ->label('Dia do mês')
                            ->integer()
                            ->minValue(1)
                            ->maxValue(31)
                            ->helperText('Em meses mais curtos, cai no último dia do mês. Em branco: o dia da data de início.')
                            ->visible(fn (Get $get): bool => $frequency($get)?->usesDayOfMonth() ?? true),
                        DatePicker::make('start_date')
                            ->label('Início')
                            ->displayFormat('d/m/Y')
                            ->native(false)
                            ->default(now())
                            ->helperText(fn (Get $get): string => $frequency($get) === RecurrenceFrequency::Weekly
                                ? 'Repete no mesmo dia da semana desta data.'
                                : 'Datas anteriores a hoje geram previstos já atrasados.')
                            ->required(),
                        DatePicker::make('end_date')
                            ->label('Fim')
                            ->displayFormat('d/m/Y')
                            ->native(false)
                            ->helperText('Em branco: sem fim.'),
                    ]),
                Radio::make('apply_to_generated')
                    ->label('Aplicar esta alteração a…')
                    ->options([
                        '0' => 'Só às próximas ocorrências (os previstos já gerados ficam como estão)',
                        '1' => 'Também aos previstos já gerados e ainda não pagos, de hoje em diante',
                    ])
                    ->helperText('Lançamentos pagos e atrasados nunca são alterados.')
                    ->visibleOn('edit')
                    ->dehydrated()
                    ->required()
                    ->columnSpanFull(),
            ]);
    }

    /**
     * @return array<int, string>
     */
    private static function accountOptions(?Recurrence $record): array
    {
        return Account::query()
            ->where(fn ($query) => $query->whereNull('archived_at')
                ->when($record, fn ($query) => $query->orWhere('id', $record->account_id)))
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }
}
