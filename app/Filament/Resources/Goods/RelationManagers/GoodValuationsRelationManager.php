<?php

namespace App\Filament\Resources\Goods\RelationManagers;

use App\Domain\Goods\ManageGoods;
use App\Filament\Forms\MoneyInput;
use App\Models\Good;
use App\Models\GoodValuation;
use App\Models\User;
use App\Support\MoneyFormatter;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Histórico de avaliações do bem (FIPE, laudo, anúncio de imóvel parecido…).
 */
class GoodValuationsRelationManager extends RelationManager
{
    protected static string $relationship = 'valuations';

    protected static ?string $title = 'Avaliações';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('date', 'desc')
            ->emptyStateHeading('Nenhuma avaliação')
            ->emptyStateDescription('Sem avaliação, o bem vale o valor de aquisição.')
            ->columns([
                TextColumn::make('date')->label('Data')->date('d/m/Y'),
                TextColumn::make('value')->label('Valor')->alignEnd()->weight('bold')
                    ->formatStateUsing(fn (int $state): string => MoneyFormatter::formatMinor($state)),
            ])
            ->headerActions([
                self::informAction(fn (mixed $record = null): Good => $this->good()),
            ])
            ->recordActions([
                DeleteAction::make()
                    ->using(function (GoodValuation $record): bool {
                        app(ManageGoods::class)->deleteValuation(self::user(), $record);

                        return true;
                    }),
            ]);
    }

    /**
     * Ação "Informar valor" (também usada na tela do bem e no lembrete do painel).
     *
     * @param  \Closure(mixed): Good  $good  recebe o registro da linha (quando a ação está numa tabela)
     */
    public static function informAction(\Closure $good, string $name = 'informValue'): Action
    {
        return Action::make($name)
            ->label('Informar valor')
            ->icon('heroicon-o-pencil-square')
            ->modalDescription('Valor estimado do bem na data (tabela FIPE, avaliação, anúncios parecidos). Informar de novo na mesma data substitui.')
            ->schema([
                DatePicker::make('date')->label('Data')->displayFormat('d/m/Y')->native(false)->default(now())->required(),
                MoneyInput::make('value')->label('Valor')->required(),
            ])
            ->action(function (array $data, Action $action, mixed $record = null) use ($good): void {
                try {
                    app(ManageGoods::class)->value(self::user(), $good($record), Carbon::parse($data['date']), (int) $data['value']);
                } catch (ValidationException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();
                    $action->halt();

                    return;
                }

                Notification::make()->success()->title('Valor registrado.')->send();
            });
    }

    private function good(): Good
    {
        /** @var Good */
        return $this->getOwnerRecord();
    }

    private static function user(): User
    {
        /** @var User */
        return auth()->user();
    }
}
