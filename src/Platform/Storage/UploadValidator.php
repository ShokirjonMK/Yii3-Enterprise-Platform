<?php

declare(strict_types=1);

namespace App\Platform\Storage;

use App\Shared\Domain\Exception\ValidationException;
use finfo;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Reusable server-side upload validation (never trust the client's name, extension or Content-Type):
 *  - upload error / empty / size limit,
 *  - file name sanitised; path components, control characters and double extensions
 *    (`invoice.php.jpg`, `photo.jpg.exe`, `x.pdf.png`) rejected,
 *  - extension allowlist; executable / script extensions always refused,
 *  - MIME type sniffed from content (finfo) must match the extension's allowed MIME types,
 *  - content scan: executable magic numbers (PE/ELF/Mach-O/shebang), embedded PHP / ASP / script tags
 *    (polyglots such as `GIF89a<?php …`), SVG with scripts, event handlers, javascript: URLs or foreignObject,
 *  - sha256 checksum and a randomised storage path (the original name is metadata only).
 */
final class UploadValidator
{
    /** extension => allowed sniffed MIME types */
    public const DEFAULT_ALLOWED = [
        'pdf' => ['application/pdf'],
        'png' => ['image/png'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'gif' => ['image/gif'],
        'webp' => ['image/webp'],
        'svg' => ['image/svg+xml', 'text/xml', 'application/xml', 'text/plain'],
        'txt' => ['text/plain'],
        'csv' => ['text/csv', 'text/plain', 'application/csv'],
        'json' => ['application/json', 'text/plain'],
        'xml' => ['text/xml', 'application/xml'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'],
        'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip', 'application/octet-stream'],
        'odt' => ['application/vnd.oasis.opendocument.text', 'application/zip'],
        'ods' => ['application/vnd.oasis.opendocument.spreadsheet', 'application/zip'],
        'zip' => ['application/zip'],
    ];

    /** Never accepted, regardless of configuration (also refused as an inner extension). */
    public const DANGEROUS = [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar', 'pht', 'phps', 'inc', 'cgi', 'pl', 'py', 'rb', 'sh', 'bash',
        'asp', 'aspx', 'ascx', 'ashx', 'jsp', 'jspx', 'cfm', 'exe', 'dll', 'com', 'bat', 'cmd', 'msi', 'scr', 'pif', 'vbs', 'vbe',
        'js', 'mjs', 'jse', 'wsf', 'wsh', 'ps1', 'psm1', 'jar', 'war', 'class', 'so', 'dylib', 'elf', 'bin', 'app', 'apk', 'deb', 'rpm',
        'htaccess', 'htpasswd', 'html', 'htm', 'xhtml', 'shtml', 'svgz', 'hta', 'lnk', 'reg', 'swf',
    ];

    private const EXECUTABLE_MIME = '/(x-dosexec|x-executable|x-msdownload|x-sharedlib|x-mach-binary|x-elf|x-php|x-httpd-php|x-sh|x-shellscript|x-perl|x-python|javascript|html)/i';

    /** @var array<string, list<string>> */
    private readonly array $allowed;

    /**
     * @param array<string, list<string>>|null $allowed extension => MIME allowlist (defaults to DEFAULT_ALLOWED)
     */
    public function __construct(
        private readonly int $maxBytes = 20 * 1024 * 1024,
        ?array $allowed = null,
    ) {
        $allowed ??= self::DEFAULT_ALLOWED;
        // dangerous extensions can never be configured in
        $this->allowed = array_diff_key($allowed, array_flip(self::DANGEROUS));
    }

    public function maxBytes(): int
    {
        return $this->maxBytes;
    }

    /** @return list<string> */
    public function allowedExtensions(): array
    {
        return array_keys($this->allowed);
    }

    public function validateUploaded(UploadedFileInterface $file): ValidatedUpload
    {
        if ($file->getError() !== UPLOAD_ERR_OK) {
            $message = match ($file->getError()) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The file exceeds the maximum upload size.',
                UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
                UPLOAD_ERR_PARTIAL => 'The upload was interrupted.',
                default => 'The upload failed.',
            };
            throw ValidationException::single('file', $message);
        }
        $stream = $file->getStream();
        $meta = $stream->getMetadata();
        $path = is_array($meta) ? ($meta['uri'] ?? null) : null;
        if (!is_string($path) || !is_file($path)) {
            // Copy non-file streams to a temporary file so that finfo/hash work uniformly
            $path = (string) tempnam(sys_get_temp_dir(), 'upl');
            $stream->rewind();
            file_put_contents($path, $stream->getContents());
        }
        return $this->validateFile($path, (string) $file->getClientFilename(), $file->getSize());
    }

    /**
     * @param string $path         local temporary file
     * @param string $originalName client-provided file name (untrusted)
     */
    public function validateFile(string $path, string $originalName, ?int $declaredSize = null): ValidatedUpload
    {
        $size = (int) @filesize($path);
        if ($size <= 0) {
            throw ValidationException::single('file', 'The file is empty.');
        }
        if ($size > $this->maxBytes || ($declaredSize !== null && $declaredSize > $this->maxBytes)) {
            throw ValidationException::single('file', sprintf('The file exceeds the maximum upload size (%d MB).', (int) ceil($this->maxBytes / 1048576)));
        }

        $name = $this->sanitizeName($originalName);
        $parts = explode('.', strtolower($name));
        if (count($parts) < 2 || end($parts) === '') {
            throw ValidationException::single('file', 'The file must have an extension.');
        }
        $ext = (string) array_pop($parts);
        array_shift($parts); // base name
        if (in_array($ext, self::DANGEROUS, true) || !isset($this->allowed[$ext])) {
            throw ValidationException::single('file', sprintf('Files of type .%s are not allowed.', $ext));
        }
        $known = array_merge(self::DANGEROUS, array_keys(self::DEFAULT_ALLOWED), array_keys($this->allowed));
        foreach ($parts as $inner) {
            if (in_array($inner, $known, true)) {
                throw ValidationException::single('file', 'Double extensions are not allowed.');
            }
        }

        $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->file($path);
        if (preg_match(self::EXECUTABLE_MIME, $mime) && !in_array($mime, $this->allowed[$ext], true)) {
            throw ValidationException::single('file', 'Executable or script content is not allowed.');
        }
        if (!in_array($mime, $this->allowed[$ext], true)) {
            throw ValidationException::single('file', sprintf('The file content (%s) does not match the .%s extension.', $mime, $ext));
        }

        $this->scanContent($path, $ext, $mime);

        return new ValidatedUpload(
            path: $path,
            originalName: $name,
            extension: $ext,
            mimeType: $ext === 'svg' ? 'image/svg+xml' : $mime,
            size: $size,
            sha256: (string) hash_file('sha256', $path),
        );
    }

    /** Randomised object key: `<scope>/<yyyy>/<mm>/<32 hex>.<ext>` — never derived from the client name. */
    public static function storagePath(string $extension, ?string $scope = null): string
    {
        $scope = $scope !== null && preg_match('/^[a-z0-9-]{1,64}$/', $scope) ? $scope : 'global';
        return sprintf('%s/%s/%s.%s', $scope, date('Y/m'), bin2hex(random_bytes(16)), preg_replace('/[^a-z0-9]/', '', strtolower($extension)));
    }

    private function sanitizeName(string $name): string
    {
        if ($name === '' || preg_match('/[\x00-\x1F\x7F]/', $name)) {
            throw ValidationException::single('file', 'Invalid file name.');
        }
        // keep only the last path component (Windows and POSIX separators)
        $name = basename(str_replace('\\', '/', $name));
        $name = trim($name, " .\t");
        if ($name === '' || $name === '..' || mb_strlen($name) > 200) {
            throw ValidationException::single('file', 'Invalid file name.');
        }
        return (string) preg_replace('/[^\p{L}\p{N} ._()\-]/u', '_', $name);
    }

    private function scanContent(string $path, string $ext, string $mime): void
    {
        $fh = fopen($path, 'rb');
        if ($fh === false) {
            throw ValidationException::single('file', 'The file could not be read.');
        }
        $head = (string) fread($fh, 8);
        fclose($fh);
        $magic = [
            "MZ" => 'PE executable', "\x7FELF" => 'ELF executable', "\xCF\xFA\xED\xFE" => 'Mach-O', "\xFE\xED\xFA\xCE" => 'Mach-O',
            "\xFE\xED\xFA\xCF" => 'Mach-O', "\xCA\xFE\xBA\xBE" => 'Mach-O/Java class', '#!' => 'script',
        ];
        foreach ($magic as $sig => $label) {
            if (str_starts_with($head, $sig)) {
                throw ValidationException::single('file', 'Executable or script content is not allowed.');
            }
        }
        // Containers (zip/office) are compressed: scanning their bytes for tags is meaningless.
        if (in_array($ext, ['zip', 'docx', 'xlsx', 'pptx', 'odt', 'ods'], true)) {
            return;
        }
        $content = (string) file_get_contents($path);
        if (preg_match('/<\?(php|=)|<\?\s|<%|<script\b|__halt_compiler/i', $content)) {
            throw ValidationException::single('file', 'The file contains embedded script code.');
        }
        if ($ext === 'svg' || str_contains($mime, 'svg')) {
            if (preg_match('/<script\b|\bon[a-z]+\s*=|javascript\s*:|<foreignObject\b|<!ENTITY|xlink:href\s*=\s*["\']\s*(?!#)|<iframe\b|<embed\b|<object\b/i', $content)) {
                throw ValidationException::single('file', 'SVG files with scripts, event handlers or external references are not allowed.');
            }
            if (!preg_match('/<svg\b/i', $content)) {
                throw ValidationException::single('file', 'The file is not a valid SVG image.');
            }
        }
    }
}
