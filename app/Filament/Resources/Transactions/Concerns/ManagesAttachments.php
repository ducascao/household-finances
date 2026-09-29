<?php

namespace App\Filament\Resources\Transactions\Concerns;

use App\Filament\Resources\Transactions\Actions\AttachmentActions;

/**
 * Métodos Livewire usados pela lista de comprovantes dentro do modal.
 */
trait ManagesAttachments
{
    public function deleteAttachment(int $attachmentId): void
    {
        AttachmentActions::delete($attachmentId);
    }
}
