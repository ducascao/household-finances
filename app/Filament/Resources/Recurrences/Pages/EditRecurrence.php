<?php

namespace App\Filament\Resources\Recurrences\Pages;

use App\Domain\Recurrences\UpdateRecurrence;
use App\Filament\Concerns\RunsDomainActions;
use App\Filament\Resources\Recurrences\RecurrenceResource;
use App\Models\Recurrence;
use App\Models\User;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * @property Recurrence $record
 */
class EditRecurrence extends EditRecord
{
    use RunsDomainActions;

    protected static string $resource = RecurrenceResource::class;

    /**
     * O formulário trabalha com o valor sem sinal; o sinal vem da categoria.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['amount'] = $this->record->amount->abs()->getMinorAmount()->toInt();
        $data['apply_to_generated'] = null;

        return $data;
    }

    /**
     * @param  Recurrence  $record
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var User $user */
        $user = auth()->user();
        $applyToGenerated = (bool) (int) ($data['apply_to_generated'] ?? 0);

        return $this->runDomainAction(fn () => app(UpdateRecurrence::class)->execute($user, $record, $data, $applyToGenerated));
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
