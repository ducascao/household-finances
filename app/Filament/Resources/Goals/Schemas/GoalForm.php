<?php

namespace App\Filament\Resources\Goals\Schemas;

use App\Enums\AccountType;
use App\Enums\AccountVisibility;
use App\Filament\Forms\MoneyInput;
use App\Models\Account;
use App\Models\Asset;
use App\Models\Goal;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;

class GoalForm
{
    public static function configure(Schema $schema): Schema
    {
        $shared = fn (Get $get): bool => ($get('visibility') instanceof AccountVisibility ? $get('visibility')->value : $get('visibility')) === AccountVisibility::Shared->value;

        return $schema->components([
            Section::make('Meta')
                ->columns(2)
                ->columnSpanFull()
                ->schema([
                    TextInput::make('name')->label('Nome')->placeholder('Reserva de emergência, viagem…')->required()->maxLength(255),
                    MoneyInput::make('target')->label('Valor-alvo')->required(),
                    DatePicker::make('deadline')->label('Prazo')->displayFormat('d/m/Y')->native(false)->required(),
                    Select::make('visibility')->label('Visibilidade')->options(AccountVisibility::options())
                        ->default(AccountVisibility::Shared->value)->live()->required()
                        ->disabled(fn (?Goal $record): bool => $record !== null && $record->owner_id !== Auth::id())
                        ->dehydrated()
                        ->helperText('Compartilhada: só pode usar contas compartilhadas. Pessoal: só você vê.'),
                    Textarea::make('notes')->label('Observações')->columnSpanFull(),
                ]),
            Section::make('De onde vem o progresso')
                ->description('O progresso é o saldo atual das contas e o valor dos investimentos marcados aqui. Funciona melhor com o dinheiro da meta separado.')
                ->columns(2)
                ->columnSpanFull()
                ->schema([
                    CheckboxList::make('account_ids')->label('Contas')
                        ->options(fn (Get $get): array => Account::query()
                            ->whereNull('archived_at')
                            ->where('type', '!=', AccountType::CreditCard->value)
                            ->when($shared($get), fn ($query) => $query->where('visibility', AccountVisibility::Shared->value))
                            ->orderBy('name')->pluck('name', 'id')->all()),
                    CheckboxList::make('asset_ids')->label('Investimentos')
                        ->options(fn (Get $get): array => Asset::query()
                            ->when($shared($get), fn ($query) => $query->whereHas('account', fn ($q) => $q->where('visibility', AccountVisibility::Shared->value)))
                            ->orderBy('name')->get()
                            ->mapWithKeys(fn (Asset $asset): array => [$asset->id => $asset->label().($asset->ticker !== null ? '' : ' ('.$asset->type->label().')')])
                            ->all()),
                ]),
        ]);
    }
}
