<?php

namespace App\Filament\Resources\Assets\RelationManagers;

use App\Domain\Investments\PriceBook;
use App\Domain\Investments\Quantity;
use App\Enums\PriceSource;
use App\Models\Asset;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class PricesRelationManager extends RelationManager
{
    protected static string $relationship = 'prices';

    protected static ?string $title = 'Cotações';

    /**
     * Só para ativos da B3 (renda fixa e previdência usam saldos informados).
     */
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof Asset && ! $ownerRecord->type->isValuedByBalance();
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort(fn ($query) => $query->orderByDesc('date')->orderByDesc('id'))
            ->paginated([10, 30, 90])
            ->columns([
                TextColumn::make('date')->label('Data')->date('d/m/Y'),
                TextColumn::make('price')->label('Cotação')->alignEnd()
                    ->formatStateUsing(fn (string $state): string => 'R$ '.Quantity::format($state, 2)),
                TextColumn::make('source')->label('Fonte')->badge()
                    ->formatStateUsing(fn (PriceSource $state): string => $state->label())
                    ->color(fn (PriceSource $state): string => $state === PriceSource::Manual ? 'warning' : 'gray'),
            ])
            ->headerActions([
                Action::make('manualPrice')
                    ->label('Ajustar cotação')
                    ->modalDescription('Na mesma data, a cotação manual vale mais que a automática.')
                    ->schema([
                        DatePicker::make('date')->label('Data')->displayFormat('d/m/Y')->native(false)->default(now())->required(),
                        TextInput::make('price')->label('Cotação (R$)')->required(),
                    ])
                    ->action(function (array $data, Action $action): void {
                        /** @var User $user */
                        $user = auth()->user();
                        /** @var Asset $asset */
                        $asset = $this->getOwnerRecord();

                        try {
                            app(PriceBook::class)->setManual($user, $asset, Carbon::parse($data['date']), (string) $data['price']);
                        } catch (ValidationException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();
                            $action->halt();
                        }
                    }),
            ]);
    }
}
