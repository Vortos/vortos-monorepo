<?php

declare(strict_types=1);

namespace Vortos\Observability\Sink;

/**
 * The wire transport an {@see ErrorSinkInterface} driver uses to deliver one captured
 * error to its backend. Abstracted so the durability/never-throw logic of a sink is
 * unit-testable without real network I/O (a fake transport asserts ordering and
 * failure handling).
 */
interface ErrorTransportInterface
{
    /**
     * Deliver one error to the given ingest endpoint. Returns true on success.
     * Implementations must be bounded (hard timeout) and must not block indefinitely.
     */
    public function send(string $ingestUrl, CapturedError $error): bool;
}
