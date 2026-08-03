<?php

declare(strict_types=1);

namespace OneMonitor\Sdk\Tests;

use Psr\Log\AbstractLogger;
use Stringable;

final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    public array $records = [];

    /** @param array<string, mixed> $context */
    public function log(mixed $level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = [
            'level' => is_string($level) ? $level : gettype($level),
            'message' => (string) $message,
            'context' => $context,
        ];
    }
}
