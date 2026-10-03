<?php

declare(strict_types=1);

namespace OneMonitor\Sdk;

use OneMonitor\Sdk\Exception\InvalidArgumentException;

/**
 * Sends pings to 1Monitor.
 *
 * Every method returns `true` when 1Monitor accepted the ping and `false`
 * otherwise. Delivery never throws; only an empty token does.
 *
 * Type-hint against this interface rather than {@see Client} so the SDK can
 * be replaced with a test double in your own test suite.
 */
interface ClientInterface
{
    /**
     * Records a liveness ping — the job is alive.
     *
     * @param int|null    $exitCode The job's exit code; values outside 0–255 are ignored by the server.
     * @param string|null $output   The job's output, sent as the request body; an empty string sends none.
     * @param string|null $version  The deployed version, e.g. a release or commit SHA; overrides the client's
     *                              default. Trimmed; anything but 1–64 printable, non-whitespace ASCII characters
     *                              is dropped.
     *
     * @throws InvalidArgumentException on an empty token
     */
    public function ping(
        string $token,
        ?int $exitCode = null,
        ?string $output = null,
        ?string $version = null,
    ): bool;

    /**
     * Opens a run — the job has started.
     *
     * @param string|null $version The deployed version, e.g. a release or commit SHA; overrides the client's
     *                             default. Trimmed; anything but 1–64 printable, non-whitespace ASCII characters
     *                             is dropped.
     *
     * @throws InvalidArgumentException on an empty token
     */
    public function pingStart(string $token, ?string $version = null): bool;

    /**
     * Closes the open run as successful.
     *
     * @param int|null    $exitCode The job's exit code; values outside 0–255 are ignored by the server.
     * @param string|null $output   The job's output, sent as the request body; an empty string sends none.
     * @param string|null $version  The deployed version, e.g. a release or commit SHA; overrides the client's
     *                              default. Trimmed; anything but 1–64 printable, non-whitespace ASCII characters
     *                              is dropped.
     *
     * @throws InvalidArgumentException on an empty token
     */
    public function pingSuccess(
        string $token,
        ?int $exitCode = null,
        ?string $output = null,
        ?string $version = null,
    ): bool;

    /**
     * Closes the open run as failed.
     *
     * @param int|null    $exitCode The job's exit code; values outside 0–255 are ignored by the server.
     * @param string|null $output   The job's output, sent as the request body; an empty string sends none.
     * @param string|null $version  The deployed version, e.g. a release or commit SHA; overrides the client's
     *                              default. Trimmed; anything but 1–64 printable, non-whitespace ASCII characters
     *                              is dropped.
     *
     * @throws InvalidArgumentException on an empty token
     */
    public function pingFail(
        string $token,
        ?int $exitCode = null,
        ?string $output = null,
        ?string $version = null,
    ): bool;
}
