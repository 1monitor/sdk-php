<?php

declare(strict_types=1);

namespace OneMonitor\Sdk\Exception;

/**
 * Misconfiguration: a bad constructor option or an empty ping token. These are
 * programmer errors and are the only thing the SDK throws — delivering a ping
 * never does.
 */
final class InvalidArgumentException extends \InvalidArgumentException implements Exception
{
}
