<?php

namespace App\Filament\Resources\Debts\Pages;

use App\Domain\Debts\DebtSummary;
use App\Domain\Debts\ManageDebts;
use App\Enums\DebtSystem;
use App\Enums\PrepaymentMode;
use App\Filament\Forms\MoneyInput;
use App\Filament\Resources\Debts\DebtResource;
use App\Models\Debt;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * @property Debt $record
 */
class ViewDebt extends ViewRecord
{
    protected static string $resource = DebtResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('prepay')
                ->label('Amortizar')
                ->icon(Heroicon::OutlinedArrowTrendingDown)
                ->visible(fn (): bool => ! (new DebtSummary($this->record))->isPaidOff())
                ->modalHeading('Amortização extraordinária')
                ->modalDescription('O valor sai da conta de pagamento e abate o saldo devedor. As parcelas ainda não pagas são recalculadas.')
                ->schema([
                    DatePicker::make('date')->label('Data')->displayFormat('d/m/Y')->native(false)->default(now())->required(),
                    MoneyInput::make('amount')->label('Valor')->required(),
                    Radio::make('mode')->label('O que reduzir')->options(PrepaymentMode::options())->default(PrepaymentMode::ReduceTerm->value)->required()
                        ->helperText('Reduzir o prazo costuma economizar mais juros.'),
                ])
                ->action(function (array $data, Action $action): void {
                    try {
                        app(ManageDebts::class)->prepay($this->user(), $this->record, Carbon::parse($data['date']), (int) $data['amount'], PrepaymentMode::from((string) $data['mode']));
                    } catch (ValidationException $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();
                        $action->halt();

                        return;
                    }

                    Notification::make()->success()->title('Amortização registrada e parcelas recalculadas.')->send();
                }),
            Action::make('adjustBalance')
                ->label('Ajustar saldo devedor')
                ->icon(Heroicon::OutlinedScale)
                ->color('gray')
                ->visible(fn (): bool => $this->record->system !== DebtSystem::Custom && ! (new DebtSummary($this->record))->isPaidOff())
                ->modalDescription('Informe o saldo devedor que o banco mostra hoje (depois da última parcela paga). As parcelas ainda não pagas são recalculadas a partir dele.')
                ->schema([
                    MoneyInput::make('balance')->label('Saldo devedor no banco')->required(),
                    DatePicker::make('date')->label('Data')->displayFormat('d/m/Y')->native(false)->default(now())->maxDate(now())->required(),
                ])
                ->action(function (array $data, Action $action): void {
                    try {
                        app(ManageDebts::class)->adjustBalance($this->user(), $this->record, Carbon::parse($data['date']), (int) $data['balance']);
                    } catch (ValidationException $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();
                        $action->halt();

                        return;
                    }

                    Notification::make()->success()->title('Saldo ajustado e parcelas recalculadas.')->send();
                }),
            Action::make('delete')
                ->label('Excluir')
                ->color('danger')
                ->icon(Heroicon::OutlinedTrash)
                ->requiresConfirmation()
                ->modalDescription('Só é possível excluir dívidas sem parcelas pagas. Os lançamentos previstos também são excluídos.')
                ->action(function (Action $action): void {
                    try {
                        app(ManageDebts::class)->delete($this->user(), $this->record);
                    } catch (ValidationException $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();
                        $action->halt();

                        return;
                    }

                    $this->redirect(DebtResource::getUrl('index'));
                }),
        ];
    }

    private function user(): User
    {
        /** @var User */
        return auth()->user();
    }
}
