<?php

namespace App\Console\Commands;

use App\Contracts\AttachmentStorage;
use App\Domain\Attachments\AttachmentStorageUnavailable;
use App\Models\GoogleConnection;
use App\Models\Household;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;

/**
 * Envia o dump mais recente de ./backups para a pasta "Backups" no Drive do lar e mantém 30 dias lá.
 * O dump contém o banco inteiro: por isso só vai para um lar (GOOGLE_BACKUP_HOUSEHOLD_ID ou o único lar conectado).
 */
class BackupToDriveCommand extends Command
{
    public const FOLDER = 'Backups';

    public const RETENTION_DAYS = 30;

    protected $signature = 'app:backup-to-drive';

    protected $description = 'Copia o backup mais recente do Postgres para o Google Drive do lar';

    public function handle(AttachmentStorage $storage): int
    {
        $household = $this->household();

        if ($household === null) {
            $this->warn('Nenhum lar definido para receber o backup (configure GOOGLE_BACKUP_HOUSEHOLD_ID ou conecte o Drive de um único lar).');

            return self::SUCCESS;
        }

        $latest = $this->latestDump();

        if ($latest === null) {
            $this->warn('Nenhum arquivo .dump encontrado em '.$this->backupDir().'.');

            return self::SUCCESS;
        }

        try {
            $disk = $storage->disk($household);
        } catch (AttachmentStorageUnavailable $e) {
            $this->warn($e->getMessage());

            return self::SUCCESS;
        }

        $target = self::FOLDER.'/'.basename($latest);

        if ($disk->exists($target)) {
            $this->info('Backup '.basename($latest).' já está no Drive.');
        } else {
            $stream = fopen($latest, 'r');
            $disk->writeStream($target, $stream);
            is_resource($stream) && fclose($stream);
            $this->info('Backup '.basename($latest).' enviado ao Drive.');
        }

        $this->prune($disk);

        return self::SUCCESS;
    }

    private function household(): ?Household
    {
        $configured = config('services.google.backup_household_id');

        if (filled($configured)) {
            return Household::find((int) $configured);
        }

        $connected = GoogleConnection::withoutGlobalScopes()->pluck('household_id');

        if (config('attachments.disk') !== null) {
            $connected = Household::query()->pluck('id');
        }

        return $connected->count() === 1 ? Household::find($connected->first()) : null;
    }

    private function backupDir(): string
    {
        $path = (string) config('backup.path');

        return str_starts_with($path, '/') ? $path : base_path(ltrim($path, './'));
    }

    private function latestDump(): ?string
    {
        $files = glob($this->backupDir().'/*.dump') ?: [];
        usort($files, fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));

        return $files[0] ?? null;
    }

    private function prune(Filesystem $disk): void
    {
        $limit = now()->subDays(self::RETENTION_DAYS)->getTimestamp();

        foreach ($disk->files(self::FOLDER) as $file) {
            if (str_ends_with($file, '.dump') && $disk->lastModified($file) < $limit) {
                $disk->delete($file);
                $this->line('Removido do Drive (mais de '.self::RETENTION_DAYS." dias): {$file}");
            }
        }
    }
}
