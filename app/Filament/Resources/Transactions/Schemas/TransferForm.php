<?php

namespace App\Filament\Resources\Transactions\Schemas;

use App\Domain\Transfers\TransferLegs;
use App\Enums\TransactionStatus;
use App\Filament\Forms\MoneyInput;
use App\Models\Account;
use App\Models\Transaction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;

class TransferForm
{
    /**
     * @param  list<int>  $currentAccountIds  contas atuais (entram nas opções mesmo se arquivadas)
     * @return list<Component|Field>
     */
    public static function fields(array $currentAccountIds = []): array
    {
        $accounts = fn (): array => Account::query()
            ->where(fn ($query) => $query->whereNull('archived_at')->orWhereIn('id', $currentAccountIds))
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (Account $account): array => [$account->id => "{$account->name} ({$account->currency})"])
            ->all();

        $isPaid = fn (Get $get): bool => (($status = $get('status')) instanceof TransactionStatus ? $status->value : $status) !== TransactionStatus::Scheduled->value;

        return [
            Select::make('from_account_id')
                ->label('De')
                ->options($accounts)
                ->required(),
            Select::make('to_account_id')
                ->label('Para')
                ->options($accounts)
                ->different('from_account_id')
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
                ->visible($isPaid)
                ->required($isPaid),
            DatePicker::make('due_date')
                ->label('Vencimento')
                ->displayFormat('d/m/Y')
                ->native(false)
                ->visible(fn (Get $get): bool => ! $isPaid($get))
                ->required(fn (Get $get): bool => ! $isPaid($get)),
            TextInput::make('description')
                ->label('Descrição')
                ->default('Transferência')
                ->maxLength(255),
            Textarea::make('notes')
                ->label('Observações')
                ->columnSpanFull(),
        ];
    }

    /**
     * Estado do formulário a partir de uma das pernas.
     *
     * @return array<string, mixed>
     */
    public static function fillFrom(Transaction $leg): array
    {
        $legs = TransferLegs::of($leg);

        return [
            'from_account_id' => $legs->out->account_id,
            'to_account_id' => $legs->in->account_id,
            'amount' => $legs->in->amount->getMinorAmount()->toInt(),
            'status' => $legs->out->status->value,
            'date' => $legs->out->date->toDateString(),
            'due_date' => $legs->out->due_date?->toDateString(),
            'description' => $legs->out->description,
            'notes' => $legs->out->notes,
        ];
    }
}
