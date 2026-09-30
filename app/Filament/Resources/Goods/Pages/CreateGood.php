<?php

namespace App\Filament\Resources\Goods\Pages;

use App\Domain\Goods\ManageGoods;
use App\Filament\Concerns\RunsDomainActions;
use App\Filament\Resources\Goods\GoodResource;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateGood extends CreateRecord
{
    use RunsDomainActions;

    protected static string $resource = GoodResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        /** @var User $user */
        $user = auth()->user();

        return $this->runDomainAction(fn () => app(ManageGoods::class)->save($user, $data));
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
