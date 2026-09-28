<?php

namespace App\Filament\Resources\Transactions\Actions;

use App\Domain\CreditCard\CreateInstallmentPurchase;
use App\Domain\CreditCard\UpdateInstallmentPurchase;
use App\Enums\AccountType;
use App\Filament\Forms\MoneyInput;
use App\Filament\Resources\Transactions\Schemas\TransactionForm;
use App\Models\Account;
use App\Models\InstallmentGroup;
use App\Models\Transaction;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;

class InstallmentActions
{
    public static function create(): Action
    {
        return Action::make('createInstallments')
            ->label('Compra parcelada')
            ->icon(Heroicon::OutlinedCreditCard)
            ->color('gray')
            ->modalHeading('Compra parcelada no cartão')
            ->schema(fn (Schema $schema): Schema => $schema->columns(2)->components([
                Select::make('account_id')
                    ->label('Cartão')
                    ->options(fn (): array => Account::query()
                        ->where('type', AccountType::CreditCard->value)
                        ->whereNull('archived_at')
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->required(),
                Select::make('category_id')
                    ->label('Categoria')
                    ->options(fn (): array => TransactionForm::categoryOptions())
                    ->searchable()
                    ->required(),
                MoneyInput::make('amount')
                    ->label('Valor total')
                    ->required(),
                TextInput::make('installments')
                    ->label('Parcelas')
                    ->integer()
                    ->minValue(2)
                    ->maxValue(72)
                    ->required(),
                DatePicker::make('date')
                    ->label('Data da compra')
                    ->displayFormat('d/m/Y')
                    ->native(false)
                    ->default(now())
                    ->required(),
                TextInput::make('description')
                    ->label('Descrição')
                    ->helperText('As parcelas recebem "(1/N)", "(2/N)"… no fim.')
                    ->required()
                    ->maxLength(240),
                Select::make('paid_by')
                    ->label('Pago por')
                    ->options(fn (): array => TransactionForm::memberOptions())
                    ->default(fn (): ?int => auth()->id())
                    ->required(),
            ]))
            ->action(function (array $data, Action $action): void {
                try {
                    $group = app(CreateInstallmentPurchase::class)->execute(self::user(), $data);
                } catch (ValidationException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();
                    $action->halt();

                    return;
                }

                Notification::make()->success()->title("Compra lançada em {$group->installments} parcelas.")->send();
            });
    }

    public static function edit(): Action
    {
        return Action::make('editInstallments')
            ->label('Editar parcelamento')
            ->icon(Heroicon::OutlinedPencilSquare)
            ->modalHeading('Editar compra parcelada')
            ->modalDescription('Altera todas as parcelas. Para mudar valor, data ou número de parcelas, exclua e lance de novo.')
            ->visible(fn (Transaction $record): bool => $record->installment_group_id !== null)
            ->authorize(fn (Transaction $record): bool => self::user()->can('update', $record))
            ->fillForm(function (Transaction $record): array {
                $group = InstallmentGroup::withoutGlobalScopes()->findOrFail($record->installment_group_id);

                return ['description' => $group->description, 'category_id' => $group->category_id];
            })
            ->schema([
                TextInput::make('description')
                    ->label('Descrição')
                    ->required()
                    ->maxLength(240),
                Select::make('category_id')
                    ->label('Categoria')
                    ->options(fn (): array => TransactionForm::categoryOptions())
                    ->searchable()
                    ->required(),
            ])
            ->action(function (Transaction $record, array $data, Action $action): void {
                try {
                    app(UpdateInstallmentPurchase::class)->execute(
                        self::user(),
                        InstallmentGroup::withoutGlobalScopes()->findOrFail($record->installment_group_id),
                        $data,
                    );
                } catch (ValidationException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();
                    $action->halt();
                }
            });
    }

    private static function user(): User
    {
        /** @var User */
        return auth()->user();
    }
}
