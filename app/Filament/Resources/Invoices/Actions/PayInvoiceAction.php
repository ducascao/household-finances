<?php

namespace App\Filament\Resources\Invoices\Actions;

use App\Domain\CreditCard\InvoiceTotals;
use App\Domain\CreditCard\PayInvoice;
use App\Enums\AccountType;
use App\Filament\Forms\MoneyInput;
use App\Models\Account;
use App\Models\Invoice;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class PayInvoiceAction
{
    public static function make(): Action
    {
        return Action::make('payInvoice')
            ->label('Pagar fatura')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->modalHeading(fn (Invoice $record): string => 'Pagar fatura de '.$record->label())
            ->modalDescription('Registra uma transferência da conta escolhida para o cartão e marca a fatura como paga.')
            ->visible(fn (Invoice $record): bool => ! $record->isPaid())
            ->authorize(fn (Invoice $record): bool => self::user()->can('pay', $record))
            ->fillForm(fn (Invoice $record): array => [
                'date' => today()->toDateString(),
                'amount' => InvoiceTotals::amountDue($record)->getMinorAmount()->toInt(),
            ])
            ->schema([
                Select::make('from_account_id')
                    ->label('Pagar com a conta')
                    ->options(fn (): array => Account::query()
                        ->whereNull('archived_at')
                        ->where('type', '!=', AccountType::CreditCard->value)
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->required(),
                DatePicker::make('date')
                    ->label('Data do pagamento')
                    ->displayFormat('d/m/Y')
                    ->native(false)
                    ->required(),
                MoneyInput::make('amount')
                    ->label('Valor')
                    ->helperText('Padrão: o total da fatura.')
                    ->required(),
            ])
            ->action(function (Invoice $record, array $data, Action $action): void {
                try {
                    app(PayInvoice::class)->execute(self::user(), $record, (int) $data['from_account_id'], Carbon::parse($data['date']), (int) $data['amount']);
                } catch (ValidationException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();
                    $action->halt();

                    return;
                }

                Notification::make()->success()->title('Fatura paga.')->send();
            });
    }

    private static function user(): User
    {
        /** @var User */
        return auth()->user();
    }
}
