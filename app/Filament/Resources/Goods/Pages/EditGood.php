<?php

namespace App\Filament\Resources\Goods\Pages;

use App\Domain\Goods\ManageGoods;
use App\Filament\Concerns\RunsDomainActions;
use App\Filament\Resources\Goods\GoodResource;
use App\Models\Good;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * @property Good $record
 */
class EditGood extends EditRecord
{
    use RunsDomainActions;

    protected static string $resource = GoodResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->using(function (Good $record): bool {
                    app(ManageGoods::class)->delete($this->user(), $record);

                    return true;
                }),
        ];
    }

    /**
     * @param  Good  $record
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return $this->runDomainAction(fn () => app(ManageGoods::class)->save($this->user(), $data, $record));
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }

    private function user(): User
    {
        /** @var User */
        return auth()->user();
    }
}
