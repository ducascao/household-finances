<?php

namespace App\Filament\Resources\ImportBatches\Actions;

use App\Domain\Import\ConfirmImport;
use App\Domain\Import\DiscardImport;
use App\Domain\Import\ImportStatement;
use App\Enums\ImportFormat;
use App\Enums\ImportLineAction;
use App\Filament\Resources\ImportBatches\ImportBatchResource;
use App\Models\Account;
use App\Models\ImportBatch;
use App\Models\ImportLine;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ImportActions
{
    public const DISK = 'local';

    /**
     * Envia o extrato, cria o lote em revisão e abre a revisão. O arquivo é apagado depois de lido.
     */
    public static function upload(): Action
    {
        return Action::make('importStatement')
            ->label('Importar extrato')
            ->icon(Heroicon::OutlinedArrowUpTray)
            ->modalHeading('Importar extrato')
            ->modalDescription('OFX (recomendado quando o banco oferece), CSV ou PDF do extrato/fatura. Nada entra nos lançamentos antes da revisão.')
            ->schema([
                Select::make('account_id')
                    ->label('Conta ou cartão')
                    ->options(fn (): array => Account::query()->whereNull('archived_at')->orderBy('name')->pluck('name', 'id')->all())
                    ->required(),
                FileUpload::make('file')
                    ->label('Arquivo')
                    ->disk(self::DISK)
                    ->directory('imports')
                    ->visibility('private')
                    ->storeFileNamesIn('file_name')
                    ->rules(['extensions:ofx,csv,txt,pdf'])
                    ->maxSize(10240)
                    ->required(),
                TextInput::make('password')
                    ->label('Senha do PDF')
                    ->password()
                    ->revealable()
                    ->autocomplete('off')
                    ->helperText('Só para PDF protegido (muitos bancos usam o CPF). Usada para ler o arquivo e descartada.'),
            ])
            ->action(function (array $data, Action $action): void {
                $path = (string) $data['file'];
                $name = (string) ($data['file_name'] ?? basename($path));
                $format = match (strtolower(pathinfo($name, PATHINFO_EXTENSION))) {
                    'ofx' => ImportFormat::Ofx,
                    'pdf' => ImportFormat::Pdf,
                    default => ImportFormat::Csv,
                };
                $password = filled($data['password'] ?? null) ? (string) $data['password'] : null;

                try {
                    $batch = app(ImportStatement::class)->execute(self::user(), (int) $data['account_id'], (string) Storage::disk(self::DISK)->get($path), $name, $format, $password);
                } catch (ValidationException $e) {
                    Notification::make()->danger()->title('Não foi possível importar')->body($e->getMessage())->persistent()->send();
                    $action->halt();

                    return;
                } finally {
                    Storage::disk(self::DISK)->delete($path);
                }

                $action->redirect(ImportBatchResource::getUrl('view', ['record' => $batch]));
            });
    }

    public static function confirm(): Action
    {
        return Action::make('confirmImport')
            ->label('Confirmar importação')
            ->icon(Heroicon::OutlinedCheck)
            ->color('success')
            ->requiresConfirmation()
            ->modalDescription(function (ImportBatch $record): string {
                $count = fn (ImportLineAction $action): int => ImportLine::where('import_batch_id', $record->id)->where('action', $action->value)->count();

                return "Serão criados {$count(ImportLineAction::Import)} lançamento(s), baixados {$count(ImportLineAction::Settle)} previsto(s) e ignoradas {$count(ImportLineAction::Skip)} linha(s).";
            })
            ->visible(fn (ImportBatch $record): bool => $record->isReviewing())
            ->authorize(fn (ImportBatch $record): bool => self::user()->can('update', $record))
            ->action(function (ImportBatch $record, Action $action): void {
                try {
                    $counts = app(ConfirmImport::class)->execute(self::user(), $record);
                } catch (ValidationException $e) {
                    Notification::make()->danger()->title('Importação não confirmada')->body($e->getMessage())->persistent()->send();
                    $action->halt();

                    return;
                }

                Notification::make()->success()->title('Importação concluída')
                    ->body("{$counts['imported']} criado(s), {$counts['settled']} previsto(s) baixado(s), {$counts['skipped']} ignorado(s).")
                    ->send();
            });
    }

    public static function discard(): Action
    {
        return Action::make('discardImport')
            ->label('Descartar')
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription('O lote é descartado e nenhum lançamento é criado ou alterado.')
            ->visible(fn (ImportBatch $record): bool => $record->isReviewing())
            ->authorize(fn (ImportBatch $record): bool => self::user()->can('update', $record))
            ->action(function (ImportBatch $record): void {
                app(DiscardImport::class)->execute(self::user(), $record);
                Notification::make()->success()->title('Importação descartada.')->send();
            });
    }

    private static function user(): User
    {
        /** @var User */
        return auth()->user();
    }
}
