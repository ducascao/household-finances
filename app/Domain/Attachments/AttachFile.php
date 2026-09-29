<?php

namespace App\Domain\Attachments;

use App\Contracts\AttachmentStorage;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\Household;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AttachFile
{
    public function __construct(
        private readonly AttachmentStorage $storage,
    ) {}

    /**
     * Guarda o comprovante em "<ano>/<mês>" (data do lançamento) com o nome "AAAA-MM-DD descrição - arquivo".
     */
    public function execute(User $actor, Transaction $transaction, string $contents, string $originalName, string $mimeType): Attachment
    {
        $account = Account::withoutGlobalScopes()->find($transaction->account_id);

        if ($account === null || ! $account->isVisibleTo($actor)) {
            throw ValidationException::withMessages(['file' => 'Sem acesso a este lançamento.']);
        }

        /** @var list<string> $allowed */
        $allowed = config('attachments.mime_types');

        if (! in_array($mimeType, $allowed, true)) {
            throw ValidationException::withMessages(['file' => 'Tipo de arquivo não aceito. Envie PDF ou imagem (JPG, PNG, WEBP, HEIC).']);
        }

        $size = strlen($contents);

        if ($size === 0 || $size > (int) config('attachments.max_kb') * 1024) {
            throw ValidationException::withMessages(['file' => 'O arquivo deve ter até '.((int) config('attachments.max_kb') / 1024).' MB.']);
        }

        $household = Household::findOrFail($transaction->household_id);

        if (! $this->storage->isAvailable($household)) {
            throw ValidationException::withMessages(['file' => AttachmentStorageUnavailable::notConnected()->getMessage()]);
        }

        try {
            $stored = $this->storage->put($household, $transaction->date->format('Y/m'), $this->fileName($transaction, $originalName), $contents);
        } catch (AttachmentStorageUnavailable $e) {
            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        }

        $attachment = new Attachment([
            'transaction_id' => $transaction->id,
            'disk' => $stored->disk,
            'path' => $stored->path,
            'drive_file_id' => $stored->driveFileId,
            'name' => $originalName,
            'mime_type' => $mimeType,
            'size' => $size,
            'uploaded_by' => $actor->id,
        ]);
        $attachment->household_id = $transaction->household_id;
        $attachment->save();

        return $attachment;
    }

    private function fileName(Transaction $transaction, string $originalName): string
    {
        $description = Str::limit(str_replace(['/', '\\'], '-', $transaction->description), 60, '');

        return $transaction->date->format('Y-m-d').' '.$description.' - '.str_replace(['/', '\\'], '-', $originalName);
    }
}
