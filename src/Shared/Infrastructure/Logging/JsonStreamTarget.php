<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Logging;

use App\Shared\Infrastructure\Context\RequestContext;
use Yiisoft\Log\Message;
use Yiisoft\Log\Target;

/**
 * Writes one JSON object per log record (stdout/stderr) for collection by Loki/ELK/OTel collector.
 * All context is passed through {@see Redactor}. Correlation id, user and tenant are attached automatically.
 */
final class JsonStreamTarget extends Target
{
    /** @var resource|null */
    private $handle = null;

    public function __construct(
        private readonly string $stream = 'php://stderr',
        private readonly ?RequestContext $context = null,
        private readonly string $service = 'yii3-platform',
        array $levels = [],
    ) {
        parent::__construct($levels);
    }

    protected function export(): void
    {
        $this->handle ??= fopen($this->stream, 'ab') ?: null;
        if ($this->handle === null) {
            return;
        }
        $lines = '';
        foreach ($this->getMessages() as $message) {
            $lines .= $this->format($message) . "\n";
        }
        fwrite($this->handle, $lines);
    }

    public function format(Message $message): string
    {
        $context = $message->context();
        unset($context['time'], $context['trace'], $context['memory'], $context['category']);
        $exception = $context['exception'] ?? null;
        unset($context['exception']);

        $record = [
            'ts' => $message->time()->format('Y-m-d\TH:i:s.uP'),
            'level' => $message->level(),
            'service' => $this->service,
            'category' => $message->category(),
            'msg' => Redactor::redactString($message->message()),
            'correlation_id' => $this->context?->correlationId(),
            'user_id' => $this->context?->userId(),
            'tenant_id' => $this->context?->tenantId(),
            'channel' => $this->context?->channel(),
        ];
        if ($exception !== null) {
            $record['exception'] = Redactor::redact($exception);
        }
        if ($context !== []) {
            $record['ctx'] = Redactor::redact($context);
        }

        return json_encode(array_filter($record, static fn($v) => $v !== null), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '{}';
    }
}
