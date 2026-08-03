<?php

declare(strict_types=1);

namespace OneMonitor\Sdk\Exception;

use Throwable;

/**
 * Marker for every exception this SDK throws, so callers can catch all of them
 * with one `catch (OneMonitor\Sdk\Exception\Exception)`.
 */
interface Exception extends Throwable
{
}
