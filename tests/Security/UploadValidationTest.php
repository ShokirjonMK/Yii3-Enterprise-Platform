<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Platform\Storage\UploadValidator;
use App\Shared\Domain\Exception\ValidationException;
use App\Tests\Support\AdminSession;
use App\Tests\Support\Fixtures;
use GuzzleHttp\Client;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Yiisoft\Db\Query\Query;

/**
 * Upload hardening: UploadValidator rules (pure) + the admin upload endpoint over HTTP.
 *
 * Attack payloads are assembled at runtime ({@see self::tag()}) so that this source file itself does not
 * look like a web shell to host anti-virus scanners.
 */
final class UploadValidationTest extends TestCase
{
    private const PNG_1PX = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    /** @var list<string> */
    private array $tmp = [];

    protected function tearDown(): void
    {
        foreach ($this->tmp as $f) {
            @unlink($f);
        }
        Fixtures::cleanup();
    }

    /** Server-side script open tag, e.g. tag('php') → "<" . "?php". */
    private static function tag(string $kind): string
    {
        return '<' . '?' . $kind;
    }

    /** A harmless stand-in for a web-shell body (never executed). */
    private static function script(): string
    {
        return self::tag('php') . ' /* test payload */ echo strrev("tset"); ' . '?' . '>';
    }

    private function file(string $content): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'upt');
        file_put_contents($path, $content);
        $this->tmp[] = $path;
        return $path;
    }

    private static function pdf(): string
    {
        return "%PDF-1.4\n1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n2 0 obj << /Type /Pages /Kids [] /Count 0 >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";
    }

    public function testValidFilesAreAcceptedWithChecksumAndSniffedMime(): void
    {
        $v = new UploadValidator();
        $png = (string) base64_decode(self::PNG_1PX);
        $r = $v->validateFile($this->file($png), 'Logo (final).png');
        self::assertSame('image/png', $r->mimeType);
        self::assertSame('png', $r->extension);
        self::assertSame(hash('sha256', $png), $r->sha256);
        self::assertSame('Logo (final).png', $r->originalName);

        $pdf = $v->validateFile($this->file(self::pdf()), 'report.v2.pdf');
        self::assertSame('application/pdf', $pdf->mimeType, '"v2" is not an extension → allowed');

        $svg = $v->validateFile($this->file(self::tag('xml') . ' version="1.0"?><svg xmlns="http://www.w3.org/2000/svg" width="1" height="1"><rect width="1" height="1"/></svg>'), 'icon.svg');
        self::assertSame('image/svg+xml', $svg->mimeType);

        $traversal = $v->validateFile($this->file("a,b\n1,2\n"), '../../etc/passwd.csv');
        self::assertSame('passwd.csv', $traversal->originalName, 'path components are stripped');
    }

    /** @return iterable<string, array{string, string}> */
    public static function maliciousUploads(): iterable
    {
        $png = (string) base64_decode(self::PNG_1PX);
        $gif = "GIF89a\x01\x00\x01\x00\x00\xff\x00,\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x00;";
        $svgNs = 'xmlns="http://www.w3.org/2000/svg"';
        yield 'php script' => [self::script(), 'shell.php'];
        yield 'phtml' => [self::script(), 'x.phtml'];
        yield 'phar' => [self::script(), 'x.phar'];
        yield 'php disguised as txt' => [self::script(), 'notes.txt'];
        yield 'short echo tag' => ['hello ' . self::tag('=') . ' 1 ?>', 'notes.txt'];
        yield 'GIF polyglot' => [$gif . self::script(), 'avatar.gif'];
        yield 'PNG with appended script' => [$png . self::script(), 'image.png'];
        yield 'double extension php.png' => [$png, 'invoice.php.png'];
        yield 'double extension png.exe' => [$png, 'photo.png.exe'];
        yield 'double extension pdf.png' => [$png, 'scan.pdf.png'];
        yield 'mime mismatch (text as png)' => ['just text', 'fake.png'];
        yield 'html' => ['<html><body>x</body></html>', 'page.html'];
        yield 'svg with script element' => ["<svg $svgNs><scr" . 'ipt>void 0</scr' . 'ipt></svg>', 'evil.svg'];
        yield 'svg with event handler' => ["<svg $svgNs on" . 'load="void 0"><rect/></svg>', 'evil2.svg'];
        yield 'svg with javascript url' => ["<svg $svgNs xmlns:xlink=\"http://www.w3.org/1999/xlink\"><a xlink:href=\"java" . 'script:void(0)"><rect/></a></svg>', 'evil3.svg'];
        yield 'svg with external entity' => [self::tag('xml') . ' version="1.0"?><!DOCTYPE svg [<!ENTITY x SYSTEM "file:///etc/hostname">]>' . "<svg $svgNs>&x;</svg>", 'xxe.svg'];
        yield 'svg foreignObject' => ["<svg $svgNs><foreignObject><div>x</div></foreignObject></svg>", 'fo.svg'];
        yield 'ELF executable as txt' => ["\x7FELF\x02\x01\x01\x00" . str_repeat("\x00", 64), 'readme.txt'];
        yield 'PE executable as pdf' => ['MZ' . str_repeat("\x90", 200), 'doc.pdf'];
        yield 'shebang script as csv' => ["#!/bin/sh\necho x\n", 'data.csv'];
        yield 'no extension' => ['abc', 'README'];
        yield 'empty file' => ['', 'empty.txt'];
        yield 'null byte in name' => ['abc', "a.txt\0.php"];
        yield 'htaccess' => ['AddType text/plain .png', '.htaccess'];
    }

    #[DataProvider('maliciousUploads')]
    public function testMaliciousUploadsAreRejected(string $content, string $name): void
    {
        $this->expectException(ValidationException::class);
        (new UploadValidator())->validateFile($this->file($content), $name);
    }

    public function testOversizedFileIsRejected(): void
    {
        $v = new UploadValidator(1024);
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/maximum upload size/');
        $v->validateFile($this->file(str_repeat('a', 2048)), 'big.txt');
    }

    public function testDangerousExtensionsCannotBeConfiguredIn(): void
    {
        $v = new UploadValidator(1024, ['php' => ['text/x-php'], 'txt' => ['text/plain']]);
        self::assertSame(['txt'], $v->allowedExtensions());
    }

    public function testStoragePathIsRandomAndIndependentOfTheName(): void
    {
        $a = UploadValidator::storagePath('pdf', 't-abc');
        $b = UploadValidator::storagePath('pdf', 't-abc');
        self::assertNotSame($a, $b);
        self::assertMatchesRegularExpression('#^t-abc/\d{4}/\d{2}/[0-9a-f]{32}\.pdf$#', $a);
        self::assertMatchesRegularExpression('#^global/#', UploadValidator::storagePath('pdf', '../../etc'));
    }

    public function testAdminUploadEndpointRejectsScriptsOverHttp(): void
    {
        Fixtures::resetLoginThrottle();
        [$web] = AdminSession::login(['devops_sre']);
        $web->get('/admin/storage');
        $csrf = $web->csrf();
        $http = new Client(['base_uri' => getenv('E2E_BASE_URL') ?: 'http://nginx:8080', 'cookies' => $web->cookies(), 'http_errors' => false, 'headers' => ['Accept-Language' => 'en']]);
        $name = 'pwn-' . bin2hex(random_bytes(3)) . '.php';
        $r = $http->post('/admin/storage/upload', ['multipart' => [
            ['name' => '_csrf', 'contents' => $csrf],
            ['name' => 'file', 'contents' => self::script(), 'filename' => $name, 'headers' => ['Content-Type' => 'image/png']],
        ]]);
        self::assertSame(422, $r->getStatusCode());
        self::assertStringContainsString('not allowed', (string) $r->getBody());
        self::assertFalse((new Query(Fixtures::db()))->from('stored_files')->where(['original_name' => $name])->exists());

        $poly = 'poly-' . bin2hex(random_bytes(3)) . '.gif';
        $r = $http->post('/admin/storage/upload', ['multipart' => [
            ['name' => '_csrf', 'contents' => $csrf],
            ['name' => 'file', 'contents' => "GIF89a\x01\x00\x01\x00\x00\xff\x00,\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x00;" . self::script(), 'filename' => $poly],
        ]]);
        self::assertSame(422, $r->getStatusCode());
        self::assertFalse((new Query(Fixtures::db()))->from('stored_files')->where(['original_name' => $poly])->exists());

        // CSRF is enforced for multipart uploads too
        $r = $http->post('/admin/storage/upload', ['multipart' => [
            ['name' => 'file', 'contents' => 'hello', 'filename' => 'a.txt'],
        ]]);
        self::assertContains($r->getStatusCode(), [400, 403, 422]);
    }
}
