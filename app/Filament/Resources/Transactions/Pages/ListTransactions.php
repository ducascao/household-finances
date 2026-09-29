<?php

namespace App\Filament\Resources\Transactions\Pages;

use App\Domain\Transactions\CreateTransaction;
use App\Filament\Resources\Transactions\Actions\InstallmentActions;
use App\Filament\Resources\Transactions\Actions\TransferActions;
use App\Filament\Resources\Transactions\Concerns\ManagesAttachments;
use App\Filament\Resources\Transactions\Schemas\TransactionForm;
use App\Filament\Resources\Transactions\TransactionResource;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;

class ListTransactions extends ListRecords
{
    use ManagesAttachments;

    protected static string $resource = TransactionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make('quickCreate')
                ->label('Lançamento rápido')
                ->icon(Heroicon::OutlinedBolt)
                ->modalHeading('Lançamento rápido')
                ->keyBindings(['mod+shift+l'])
                ->schema(fn (Schema $schema): Schema => $schema->components(TransactionForm::quickFields())->columns(2))
                ->using(function (array $data, Action $action) {
                    /** @var User $user */
                    $user = auth()->user();

                    try {
                        return app(CreateTransaction::class)->execute($user, $data);
                    } catch (ValidationException $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();
                        $action->halt();
                    }
                }),
            TransferActions::create(),
            InstallmentActions::create(),
            CreateAction::make()
                ->label('Novo lançamento')
                ->color('gray'),
        ];
    }
}
