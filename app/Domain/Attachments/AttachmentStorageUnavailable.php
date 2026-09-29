<?php

namespace App\Domain\Attachments;

use RuntimeException;

class AttachmentStorageUnavailable extends RuntimeException
{
    public static function notConnected(): self
    {
        return new self('O Google Drive do lar não está conectado. Um administrador pode conectar em "Google Drive".');
    }
}
