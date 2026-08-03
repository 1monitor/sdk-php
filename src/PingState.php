<?php

declare(strict_types=1);

namespace OneMonitor\Sdk;

/**
 * The kind of signal a ping carries.
 *
 * `Ping` is the bare liveness ping and has no URL suffix; the other three open
 * and close a run and are addressed as `/ping/{token}/{suffix}`.
 */
enum PingState: string
{
    case Ping = 'ping';
    case Start = 'start';
    case Success = 'success';
    case Fail = 'fail';

    public function pathSuffix(): string
    {
        return $this === self::Ping ? '' : '/' . $this->value;
    }
}
