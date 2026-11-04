<?php

declare(strict_types=1);

namespace App\Platform\Storage;

/** Result of {@see UploadValidator}: trusted metadata of an uploaded file. */
final readonly class ValidatedUpload
{
    public function __construct(
        public string $path,
        public string $originalName,
        public string $extension,
        public string $mimeType,
        public int $size,
        public string $sha256,
    ) {}
}
