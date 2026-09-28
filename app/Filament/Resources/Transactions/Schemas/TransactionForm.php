<?php

namespace App\Filament\Resources\Transactions\Schemas;

use App\Enums\CategoryType;
use App\Enums\TransactionStatus;
use App\Filament\Forms\MoneyInput;
use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;

class TransactionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                ...self::quickFields(),
                DatePicker::make('competence_date')
                    ->label('Competência')
                    ->displayFormat('m/Y')
                    ->native(false)
                    ->helperText('Mês a que o lançamento pertence. Em branco: o mês da data.'),
                Select::make('paid_by')
                    ->label('Pago por')
                    ->options(fn (): array => self::memberOptions())
                    ->default(fn (): ?int => Auth::id())
                    ->required(),
                TagsInput::make('tags')
                    ->label('Tags'),
                Textarea::make('notes')
                    ->label('Observações')
                    ->columnSpanFull(),
            ]);
    }

    /**
     * Campos do lançamento rápido: o resto assume os padrões.
     *
     * @return list<Field>
     */
    public static function quickFields(): array
    {
        return [
            Select::make('account_id')
                ->label('Conta')
                ->options(fn (?Transaction $record): array => self::accountOptions($record))
                ->searchable()
                ->required(),
            Select::make('category_id')
                ->label('Categoria')
                ->options(fn (): array => self::categoryOptions())
                ->helperText('Despesa sai negativa e receita entra positiva.')
                ->searchable()
                ->required(),
            MoneyInput::make('amount')
                ->label('Valor')
                ->required(),
            ToggleButtons::make('status')
                ->label('Situação')
                ->options(TransactionStatus::options())
                ->colors([TransactionStatus::Scheduled->value => 'warning', TransactionStatus::Paid->value => 'success'])
                ->default(TransactionStatus::Paid->value)
                ->inline()
                ->live()
                ->required(),
            DatePicker::make('date')
                ->label('Data')
                ->displayFormat('d/m/Y')
                ->native(false)
                ->default(now())
                ->visible(fn (Get $get): bool => self::isPaid($get))
                ->required(fn (Get $get): bool => self::isPaid($get)),
            DatePicker::make('due_date')
                ->label('Vencimento')
                ->displayFormat('d/m/Y')
                ->native(false)
                ->visible(fn (Get $get): bool => ! self::isPaid($get))
                ->required(fn (Get $get): bool => ! self::isPaid($get)),
            TextInput::make('description')
                ->label('Descrição')
                ->required()
                ->maxLength(255),
        ];
    }

    private static function isPaid(Get $get): bool
    {
        $status = $get('status');

        return ($status instanceof TransactionStatus ? $status->value : $status) !== TransactionStatus::Scheduled->value;
    }

    /**
     * Contas ativas visíveis ao usuário (e a atual, mesmo se arquivada).
     *
     * @return array<int, string>
     */
    public static function accountOptions(?Transaction $record = null): array
    {
        return Account::query()
            ->where(fn ($query) => $query->whereNull('archived_at')
                ->when($record, fn ($query) => $query->orWhere('id', $record->account_id)))
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * Categorias agrupadas por tipo, com o nome da pai: "Moradia › Aluguel".
     *
     * @return array<string, array<int, string>>
     */
    public static function categoryOptions(): array
    {
        $categories = Category::query()->with('parent')->get()
            ->sortBy(fn (Category $category): string => $category->fullName());

        $options = [];

        foreach ([CategoryType::Expense, CategoryType::Income] as $type) {
            $options[$type === CategoryType::Expense ? 'Despesas' : 'Receitas'] = $categories
                ->where('type', $type)
                ->mapWithKeys(fn (Category $category): array => [$category->id => $category->fullName()])
                ->all();
        }

        return $options;
    }

    /**
     * @return array<int, string>
     */
    public static function memberOptions(): array
    {
        /** @var User|null $user */
        $user = Auth::user();

        return User::query()
            ->whereHas('households', fn ($query) => $query->whereKey($user?->current_household_id))
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }
}
