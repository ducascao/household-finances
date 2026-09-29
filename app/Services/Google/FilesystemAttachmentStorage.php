<?php

namespace App\Services\Google;

use App\Contracts\AttachmentStorage;
use App\Domain\Attachments\AttachmentStorageUnavailable;
use App\Domain\Attachments\StoredFile;
use App\Models\Attachment;
use App\Models\GoogleConnection;
use App\Models\Household;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Masbug\Flysystem\GoogleDriveAdapter;
use RuntimeException;

/**
 * Guarda comprovantes num disco do Laravel. Padrão: disco "google" montado com a conta Google do lar
 * (driver registrado no AppServiceProvider). Com config('attachments.disk'), usa aquele disco
 * (ex.: "local" em desenvolvimento; nos testes, "google" com Storage::fake).
 */
class FilesystemAttachmentStorage implements AttachmentStorage
{
    public const GOOGLE = 'google';

    public function isAvailable(Household $household): bool
    {
        return config('attachments.disk') !== null || $this->connection($household->id) !== null;
    }

    public function put(Household $household, string $directory, string $fileName, $contents): StoredFile
    {
        [$diskName, $disk] = $this->diskForNewFile($household->id);
        $path = trim($directory, '/').'/'.$this->uniqueName($disk, $directory, $fileName);

        if ($disk->put($path, $contents) === false) {
            throw new RuntimeException('Não foi possível gravar o arquivo.');
        }

        return new StoredFile($diskName, $path, $this->driveFileId($disk, $path));
    }

    public function readStream(Attachment $attachment)
    {
        $stream = $this->namedDisk($attachment->disk, $attachment->household_id)->readStream($attachment->path);

        if (! is_resource($stream)) {
            throw new RuntimeException('Arquivo não encontrado no armazenamento.');
        }

        return $stream;
    }

    public function delete(string $disk, string $path, int $householdId): void
    {
        $this->namedDisk($disk, $householdId)->delete($path);
    }

    public function disk(Household $household): Filesystem
    {
        return $this->diskForNewFile($household->id)[1];
    }

    /**
     * @return array{string, Filesystem}
     */
    private function diskForNewFile(int $householdId): array
    {
        $name = config('attachments.disk') ?? self::GOOGLE;

        return [$name, $this->namedDisk($name, $householdId)];
    }

    private function namedDisk(string $name, int $householdId): Filesystem
    {
        // Disco configurado (ou "google" falso nos testes): usa o disco nomeado do Laravel.
        if ($name !== self::GOOGLE || config('attachments.disk') === self::GOOGLE) {
            return Storage::disk($name);
        }

        $connection = $this->connection($householdId) ?? throw AttachmentStorageUnavailable::notConnected();

        return Storage::build([
            'driver' => self::GOOGLE,
            'refresh_token' => $connection->refresh_token,
            'folder' => $connection->root_folder,
        ]);
    }

    private function connection(int $householdId): ?GoogleConnection
    {
        return GoogleConnection::withoutGlobalScopes()->where('household_id', $householdId)->first();
    }

    /**
     * Evita sobrescrever: "nota.pdf" vira "nota (2).pdf" se já existir.
     */
    private function uniqueName(Filesystem $disk, string $directory, string $fileName): string
    {
        $name = pathinfo($fileName, PATHINFO_FILENAME);
        $extension = pathinfo($fileName, PATHINFO_EXTENSION);
        $candidate = $fileName;

        for ($i = 2; $disk->exists(trim($directory, '/').'/'.$candidate); $i++) {
            $candidate = $name." ({$i})".($extension !== '' ? '.'.$extension : '');
        }

        return $candidate;
    }

    private function driveFileId(Filesystem $disk, string $path): ?string
    {
        if (! $disk instanceof FilesystemAdapter) {
            return null;
        }

        $adapter = $disk->getAdapter();

        if (! $adapter instanceof GoogleDriveAdapter) {
            return null;
        }

        $id = $adapter->getMetadata($path)->extraMetadata()['id'] ?? null;

        return is_string($id) ? $id : null;
    }
}
