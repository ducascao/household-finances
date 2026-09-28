<?php

namespace App\Filament\Resources\Transactions\Actions;

use App\Domain\Transactions\MarkAsPaid;
use App\Filament\Forms\MoneyInput;
use App\Models\Transaction;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class MarkAsPaidActions
{
    /**
     * Baixa um lançamento previsto, podendo ajustar data e valor.
     */
    public static function single(): Action
    {
        return Action::make('markAsPaid')
            ->label('Pagar')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->modalHeading(fn (Transaction $record): string => "Pagar: {$record->description}")
            ->visible(fn (Transaction $record): bool => $record->isScheduled())
            ->authorize(fn (Transaction $record): bool => auth()->user()?->can('update', $record) ?? false)
            ->fillForm(fn (Transaction $record): array => [
                'paid_at' => today()->toDateString(),
                'amount' => $record->amount->abs()->getMinorAmount()->toInt(),
            ])
            ->schema([
                DatePicker::make('paid_at')
                    ->label('Data do pagamento')
                    ->displayFormat('d/m/Y')
                    ->native(false)
                    ->required(),
                MoneyInput::make('amount')
                    ->label('Valor pago')
                    ->required(),
            ])
            ->action(function (Transaction $record, array $data, Action $action): void {
                try {
                    app(MarkAsPaid::class)->execute(self::user(), $record, Carbon::parse($data['paid_at']), (int) $data['amount']);
                } catch (ValidationException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();
                    $action->halt();
                }

                Notification::make()->success()->title('Lançamento pago.')->send();
            });
    }

    /**
     * Baixa os selecionados na mesma data (o valor não muda).
     */
    public static function bulk(): BulkAction
    {
        return BulkAction::make('markAsPaid')
            ->label('Marcar como pago')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->fillForm(['paid_at' => today()->toDateString()])
            ->schema([
                DatePicker::make('paid_at')
                    ->label('Data do pagamento')
                    ->displayFormat('d/m/Y')
                    ->native(false)
                    ->helperText('O valor de cada lançamento é mantido. Para ajustar o valor, use "Pagar" no lançamento.')
                    ->required(),
            ])
            ->action(function (Collection $records, array $data): void {
                /** @var Collection<int, Transaction> $records */
                try {
                    $count = app(MarkAsPaid::class)->executeMany(self::user(), $records, Carbon::parse($data['paid_at']));
                } catch (ValidationException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();

                    return;
                }

                Notification::make()->success()->title($count === 1 ? '1 lançamento pago.' : "{$count} lançamentos pagos.")->send();
            })
            ->deselectRecordsAfterCompletion();
    }

    private static function user(): User
    {
        /** @var User */
        return auth()->user();
    }
}
