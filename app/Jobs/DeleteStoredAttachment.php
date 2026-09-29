<?php

namespace App\Jobs;

use App\Contracts\AttachmentStorage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Apaga o arquivo de um comprovante removido (fora da requisição, pois o Drive pode demorar).
 */
class DeleteStoredAttachment implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $backoff = 60;

    public function __construct(
        public readonly string $disk,
        public readonly string $path,
        public readonly int $householdId,
    ) {}

    public function handle(AttachmentStorage $storage): void
    {
        $storage->delete($this->disk, $this->path, $this->householdId);
    }
}
