<?php

declare(strict_types=1);

namespace App\Api\V1\OpenApi;

use OpenApi\Generator;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;

/**
 * Builds the OpenAPI 3.1 document from swagger-php attributes in src/Api/V1 and writes it to
 * runtime/api/openapi.json (`./yii api:openapi`). Generator warnings are collected, not printed.
 */
final class OpenApiGenerator
{
    /** @var list<string> */
    private array $warnings = [];

    public function __construct(
        // directories with OpenAPI attributes: src/Api/V1 and every src/Modules/<Name>/Api
        private readonly string|array $sourceDir,
        private readonly string $outputFile,
    ) {}

    public function outputFile(): string
    {
        return $this->outputFile;
    }

    /** @return list<string> warnings from the last generation */
    public function warnings(): array
    {
        return $this->warnings;
    }

    public function generate(): string
    {
        $this->warnings = [];
        $warnings = &$this->warnings;
        $logger = new class ($warnings) extends AbstractLogger {
            /** @param list<string> $sink */
            public function __construct(private array &$sink) {}

            public function log($level, string|Stringable $message, array $context = []): void
            {
                if (in_array($level, ['debug', 'info'], true)) {
                    return;
                }
                $this->sink[] = $level . ': ' . $message;
            }
        };
        $openapi = (new Generator($logger))->setVersion('3.1.0')->generate((array) $this->sourceDir);
        if ($openapi === null) {
            throw new RuntimeException('OpenAPI generation produced no document.');
        }
        return $openapi->toJson(JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** @return int bytes written */
    public function write(): int
    {
        $json = $this->generate();
        $dir = dirname($this->outputFile);
        if (!is_dir($dir) && !mkdir($dir, 0o775, true) && !is_dir($dir)) {
            throw new RuntimeException("Cannot create directory $dir");
        }
        $tmp = $this->outputFile . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (file_put_contents($tmp, $json) === false || !rename($tmp, $this->outputFile)) {
            @unlink($tmp);
            throw new RuntimeException("Cannot write {$this->outputFile}");
        }
        return strlen($json);
    }

    /** Serves the generated file, or generates on the fly (always in dev, or when the file is missing). */
    public function document(bool $fresh): string
    {
        if (!$fresh && is_file($this->outputFile)) {
            return (string) file_get_contents($this->outputFile);
        }
        return $this->generate();
    }
}
