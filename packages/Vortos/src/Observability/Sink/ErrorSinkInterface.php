<?php

declare(strict_types=1);

namespace Vortos\Observability\Sink;

use Vortos\OpsKit\Driver\DriverInterface;

/**
 * A destination for captured errors (GlitchTip / Sentry-shaped backends).
 *
 * Errors do not flow through the OTel collector by default — they are reported
 * directly to an error backend with its own DSN — so this port has runtime
 * {@see capture()} / {@see flush()} rather than only rendering collector config.
 *
 * Contract (asserted by the TCK): {@see capture()} is **best-effort and MUST NOT
 * throw** into the caller — an error backend being down can never turn into a second
 * failure on the request path. Durability is provided by an out-of-band spool.
 */
interface ErrorSinkInterface extends DriverInterface
{
    /** Stable lower-kebab key; equals the driver's #[AsDriver] key. */
    public function name(): string;

    /** Record an error for delivery. Best-effort; never throws into the caller. */
    public function capture(CapturedError $error): void;

    /** Drain any buffered errors toward the backend. Best-effort; never throws. */
    public function flush(): void;
}
