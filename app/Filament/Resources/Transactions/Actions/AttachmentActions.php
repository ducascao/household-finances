<?php

namespace App\Filament\Resources\Transactions\Actions;

use App\Domain\Attachments\AttachFile;
use App\Domain\Attachments\DeleteAttachment;
use App\Models\Attachment;
use App\Models\Transaction;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class AttachmentActions
{
    /**
     * Modal "Comprovantes": lista os anexos (abrir, baixar, excluir) e envia novos.
     */
    public static function manage(?Transaction $transaction = null): Action
    {
        $action = Action::make('attachments')
            ->label(fn (?Transaction $record): string => 'Comprovantes'.(($count = self::count($record ?? $transaction)) > 0 ? " ({$count})" : ''))
            ->icon(Heroicon::OutlinedPaperClip)
            ->color('gray')
            ->authorize(fn (?Transaction $record): bool => self::user()->can('view', $record ?? $transaction))
            ->modalHeading(fn (?Transaction $record): string => 'Comprovantes: '.($record ?? $transaction)?->description)
            ->modalSubmitActionLabel('Enviar')
            ->modalContent(fn (?Transaction $record) => view('filament.attachments.list', [
                'attachments' => Attachment::where('transaction_id', ($record ?? $transaction)?->id)->orderBy('created_at')->get(),
            ]))
            ->schema([
                FileUpload::make('files')
                    ->label('Adicionar arquivos')
                    ->helperText('PDF ou imagem (JPG, PNG, WEBP, HEIC), até '.((int) config('attachments.max_kb') / 1024).' MB cada.')
                    ->multiple()
                    ->storeFiles(false)
                    ->acceptedFileTypes(config('attachments.mime_types'))
                    ->maxSize((int) config('attachments.max_kb')),
            ])
            ->action(function (array $data, ?Transaction $record, Action $action) use ($transaction): void {
                $target = $record ?? $transaction;
                $files = array_filter((array) ($data['files'] ?? []), fn ($file): bool => $file instanceof TemporaryUploadedFile);

                if ($target === null || $files === []) {
                    return;
                }

                try {
                    foreach ($files as $file) {
                        app(AttachFile::class)->execute(self::user(), $target, (string) $file->get(), $file->getClientOriginalName(), (string) $file->getMimeType());
                    }
                } catch (ValidationException $e) {
                    Notification::make()->danger()->title('Comprovante não enviado')->body($e->getMessage())->persistent()->send();
                    $action->halt();

                    return;
                }

                Notification::make()->success()->title(count($files) === 1 ? 'Comprovante enviado.' : count($files).' comprovantes enviados.')->send();
            });

        // Na página de edição o registro é fixo; na tabela, cada linha fornece o seu.
        return $transaction !== null ? $action->record($transaction) : $action;
    }

    /**
     * Chamado pelos botões "Excluir" da lista do modal (via Livewire).
     */
    public static function delete(int $attachmentId): void
    {
        $attachment = Attachment::find($attachmentId);

        if ($attachment === null) {
            return;
        }

        try {
            app(DeleteAttachment::class)->execute(self::user(), $attachment);
            Notification::make()->success()->title('Comprovante excluído.')->send();
        } catch (ValidationException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
        }
    }

    private static function count(?Transaction $transaction): int
    {
        if ($transaction === null) {
            return 0;
        }

        return (int) ($transaction->getAttributes()['attachments_count'] ?? Attachment::where('transaction_id', $transaction->id)->count());
    }

    private static function user(): User
    {
        /** @var User */
        return auth()->user();
    }
}
