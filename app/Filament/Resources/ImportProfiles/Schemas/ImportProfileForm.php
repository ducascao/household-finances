<?php

namespace App\Filament\Resources\ImportProfiles\Schemas;

use App\Domain\Import\BankPresets;
use App\Domain\Import\Parsers\CsvParser;
use App\Models\Account;
use App\Models\ImportProfile;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

class ImportProfileForm
{
    public static function configure(Schema $schema): Schema
    {
        $column = fn (string $name, string $label) => TextInput::make($name)->label($label)->integer()->minValue(1);

        return $schema
            ->components([
                Section::make('Conta')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        Select::make('account_id')
                            ->label('Conta')
                            ->options(fn (?ImportProfile $record): array => Account::query()
                                ->where(fn ($query) => $query
                                    ->whereDoesntHave('importProfile')
                                    ->when($record, fn ($query) => $query->orWhere('id', $record->account_id)))
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all())
                            ->required(),
                        Select::make('preset')
                            ->label('Começar de um modelo')
                            ->options(BankPresets::options())
                            ->dehydrated(false)
                            ->live()
                            ->afterStateUpdated(function (?string $state, Set $set): void {
                                if ($state === null || ! isset(BankPresets::PRESETS[$state])) {
                                    return;
                                }

                                $set('name', explode(' (', BankPresets::PRESETS[$state]['label'])[0]);

                                foreach (['amount_column', 'debit_column', 'credit_column'] as $field) {
                                    $set($field, null);
                                }

                                foreach (BankPresets::PRESETS[$state]['profile'] as $field => $value) {
                                    $set($field, $value);
                                }
                            }),
                        TextInput::make('name')
                            ->label('Nome')
                            ->required()
                            ->maxLength(255),
                    ]),
                Section::make('Arquivo')
                    ->columns(4)
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('delimiter')
                            ->label('Separador de campos')
                            ->default(';')
                            ->length(1)
                            ->live(onBlur: true)
                            ->required(),
                        TextInput::make('skip_lines')
                            ->label('Linhas a pular no início')
                            ->integer()
                            ->minValue(0)
                            ->default(0),
                        Toggle::make('has_header')
                            ->label('Tem linha de cabeçalho')
                            ->default(true),
                        Toggle::make('invert_sign')
                            ->label('Inverter sinal')
                            ->helperText('Para cartões que exportam compras como valor positivo.'),
                    ]),
                Section::make('Colunas')
                    ->description('Numeradas a partir de 1. Use a coluna de valor OU as de débito e crédito.')
                    ->columns(5)
                    ->columnSpanFull()
                    ->schema([
                        $column('date_column', 'Data')->required(),
                        $column('description_column', 'Descrição')->required(),
                        $column('amount_column', 'Valor'),
                        $column('debit_column', 'Débito'),
                        $column('credit_column', 'Crédito'),
                        TextInput::make('date_format')
                            ->label('Formato da data')
                            ->default('d/m/Y')
                            ->helperText('d/m/Y = 31/12/2026 · Y-m-d = 2026-12-31 · d-m-Y = 31-12-2026')
                            ->required(),
                        Select::make('decimal_separator')
                            ->label('Separador decimal')
                            ->options([',' => 'Vírgula (1.234,56)', '.' => 'Ponto (1,234.56)'])
                            ->default(',')
                            ->required(),
                        Select::make('thousands_separator')
                            ->label('Separador de milhar')
                            ->options(['.' => 'Ponto', ',' => 'Vírgula', ' ' => 'Espaço'])
                            ->placeholder('Nenhum'),
                    ]),
                Section::make('Conferir com um arquivo')
                    ->description('Opcional: cole as primeiras linhas do CSV para ver como as colunas são separadas. Nada é salvo.')
                    ->collapsible()
                    ->columnSpanFull()
                    ->schema([
                        Textarea::make('sample')
                            ->label('Primeiras linhas do arquivo')
                            ->rows(5)
                            ->dehydrated(false)
                            ->live(debounce: 500),
                        TextEntry::make('preview')
                            ->hiddenLabel()
                            ->visible(fn (Get $get): bool => filled($get('sample')))
                            ->state(fn (Get $get): HtmlString => self::previewTable((string) $get('sample'), (string) ($get('delimiter') ?: ';')))
                            ->html(),
                    ]),
            ]);
    }

    private static function previewTable(string $sample, string $delimiter): HtmlString
    {
        $rows = CsvParser::preview($sample, $delimiter, 6);
        $width = max(array_map('count', $rows) ?: [0]);

        $html = '<table class="text-sm"><thead><tr>';

        for ($i = 1; $i <= $width; $i++) {
            $html .= '<th class="px-2 text-left">'.$i.'</th>';
        }

        $html .= '</tr></thead><tbody>';

        foreach ($rows as $row) {
            $html .= '<tr>'.implode('', array_map(fn (string $cell): string => '<td class="px-2">'.e($cell).'</td>', $row)).'</tr>';
        }

        return new HtmlString($html.'</tbody></table>');
    }
}
