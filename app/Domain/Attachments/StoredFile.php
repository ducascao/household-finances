<?php

namespace App\Domain\Attachments;

final readonly class StoredFile
{
    public function __construct(
        public string $disk,
        public string $path,
        public ?string $driveFileId,
    ) {}
}
