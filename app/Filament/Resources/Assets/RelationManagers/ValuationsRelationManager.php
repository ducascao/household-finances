<?php

namespace App\Filament\Resources\Assets\RelationManagers;

use App\Domain\Investments\ManageValuations;
use App\Filament\Forms\MoneyInput;
use App\Models\Asset;
use App\Models\ManualValuation;
use App\Models\User;
use App\Support\MoneyFormatter;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Saldos informados (extrato da corretora/seguradora) de renda fixa e previdência.
 */
class ValuationsRelationManager extends RelationManager
{
    protected static string $relationship = 'valuations';

    protected static ?string $title = 'Saldos';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof Asset && $ownerRecord->type->isValuedByBalance();
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('date', 'desc')
            ->columns([
                TextColumn::make('date')->label('Data')->date('d/m/Y'),
                TextColumn::make('balance')->label('Saldo')->alignEnd()->weight('bold')
                    ->formatStateUsing(fn (int $state): string => MoneyFormatter::formatMinor($state)),
            ])
            ->headerActions([
                self::informAction(fn (mixed $record = null): Asset => $this->asset()),
            ])
            ->recordActions([
                DeleteAction::make()
                    ->using(function (ManualValuation $record): bool {
                        app(ManageValuations::class)->delete(self::user(), $record);

                        return true;
                    }),
            ]);
    }

    /**
     * Ação "Informar saldo" (também usada no lembrete do painel).
     *
     * @param  \Closure(mixed): Asset  $asset  recebe o registro da linha (quando a ação está numa tabela)
     */
    public static function informAction(\Closure $asset, string $name = 'informBalance'): Action
    {
        return Action::make($name)
            ->label('Informar saldo')
            ->icon('heroicon-o-pencil-square')
            ->modalDescription('Saldo bruto que aparece no extrato na data. Informar de novo na mesma data substitui.')
            ->schema([
                DatePicker::make('date')->label('Data do saldo')->displayFormat('d/m/Y')->native(false)->default(now())->required(),
                MoneyInput::make('balance')->label('Saldo')->required(),
            ])
            ->action(function (array $data, Action $action, mixed $record = null) use ($asset): void {
                try {
                    app(ManageValuations::class)->save(self::user(), $asset($record), Carbon::parse($data['date']), (int) $data['balance']);
                } catch (ValidationException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();
                    $action->halt();

                    return;
                }

                Notification::make()->success()->title('Saldo registrado.')->send();
            });
    }

    private function asset(): Asset
    {
        /** @var Asset */
        return $this->getOwnerRecord();
    }

    private static function user(): User
    {
        /** @var User */
        return auth()->user();
    }
}
