<?php

declare(strict_types=1);

namespace App\Platform\Storage;

use Aws\S3\S3Client;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\Visibility;
use Throwable;

/**
 * S3-compatible object storage (AWS S3, MinIO, Ceph RGW, SeaweedFS) via league/flysystem.
 * Objects are private by default; downloads go through the application (authorization) or
 * short-lived pre-signed URLs.
 */
final class ObjectStorage
{
    private ?S3Client $client = null;
    private ?FilesystemOperator $fs = null;

    public function __construct(private readonly array $config) {}

    public function isConfigured(): bool
    {
        return ($this->config['endpoint'] ?? null) !== null && ($this->config['key'] ?? '') !== '' && ($this->config['secret'] ?? '') !== '';
    }

    public function bucket(): string
    {
        return (string) $this->config['bucket'];
    }

    public function client(): S3Client
    {
        return $this->client ??= new S3Client([
            'version' => 'latest',
            'region' => $this->config['region'],
            'endpoint' => $this->config['endpoint'],
            'use_path_style_endpoint' => (bool) $this->config['pathStyle'],
            'credentials' => ['key' => $this->config['key'], 'secret' => $this->config['secret']],
            'http' => ['connect_timeout' => 3, 'timeout' => 30],
        ]);
    }

    public function filesystem(): FilesystemOperator
    {
        return $this->fs ??= new Filesystem(
            new AwsS3V3Adapter($this->client(), $this->bucket()),
            ['visibility' => Visibility::PRIVATE],
        );
    }

    public function ensureBucket(): void
    {
        if (!$this->client()->doesBucketExistV2($this->bucket())) {
            $this->client()->createBucket(['Bucket' => $this->bucket()]);
        }
    }

    public function presignedUrl(string $path, int $ttlSeconds = 300): string
    {
        $cmd = $this->client()->getCommand('GetObject', ['Bucket' => $this->bucket(), 'Key' => $path]);
        return (string) $this->client()->createPresignedRequest($cmd, "+{$ttlSeconds} seconds")->getUri();
    }

    /** @return array{0:string, 1:array} */
    public function health(): array
    {
        if (!$this->isConfigured()) {
            return ['not_configured', []];
        }
        try {
            $exists = $this->client()->doesBucketExistV2($this->bucket());
            return [$exists ? 'up' : 'degraded', ['bucket' => $this->bucket(), 'bucket_exists' => $exists, 'endpoint' => $this->config['endpoint']]];
        } catch (Throwable $e) {
            return ['down', ['error' => $e->getMessage()]];
        }
    }
}
