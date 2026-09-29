<?php

namespace App\Contracts;

use App\Domain\Attachments\AttachmentStorageUnavailable;
use App\Domain\Attachments\StoredFile;
use App\Models\Attachment;
use App\Models\Household;
use Illuminate\Contracts\Filesystem\Filesystem;

/**
 * Onde os comprovantes ficam guardados (Google Drive do lar, ou outro disco em desenvolvimento/testes).
 */
interface AttachmentStorage
{
    /**
     * Se o lar tem onde guardar comprovantes (Drive conectado ou disco configurado).
     */
    public function isAvailable(Household $household): bool;

    /**
     * Grava o arquivo em "<directory>/<fileName>" e devolve onde ficou.
     *
     * @param  resource|string  $contents
     */
    public function put(Household $household, string $directory, string $fileName, $contents): StoredFile;

    /**
     * @return resource
     */
    public function readStream(Attachment $attachment);

    public function delete(string $disk, string $path, int $householdId): void;

    /**
     * Disco do lar para novos arquivos (usado também pela cópia do backup).
     *
     * @throws AttachmentStorageUnavailable
     */
    public function disk(Household $household): Filesystem;
}
