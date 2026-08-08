<?php

declare(strict_types=1);

namespace OneMonitor\Sdk\Tests;

use Psr\Log\AbstractLogger;

final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    public array $records = [];

    /**
     * Deliberately untyped: psr/log 1.x declares log() without native parameter
     * types, so adding them here would be an incompatible narrowing there.
     *
     * @param mixed                $level
     * @param string|\Stringable   $message
     * @param array<string, mixed> $context
     */
    public function log($level, $message, array $context = []): void
    {
        $this->records[] = [
            'level' => is_string($level) ? $level : gettype($level),
            'message' => (string) $message,
            'context' => $context,
        ];
    }
}
