<?php

declare(strict_types=1);

namespace Vortos\Observability\Driver\Glitchtip;

use Throwable;
use Vortos\Observability\Buffer\BoundedSpool;
use Vortos\Observability\Sink\Capability\SinkCapability;
use Vortos\Observability\Sink\CapturedError;
use Vortos\Observability\Sink\ErrorSinkInterface;
use Vortos\Observability\Sink\ErrorTransportInterface;
use Vortos\OpsKit\Attribute\AsDriver;
use Vortos\OpsKit\Driver\Capability\CapabilityDescriptor;

/**
 * Default error sink (GlitchTip / Sentry-shaped). Errors are first spooled to the
 * bounded on-disk queue, then drained to the backend out-of-band — so a captured
 * error never makes a synchronous network call on the request path, and a backend
 * outage degrades to "buffered, then drained" rather than a second failure.
 *
 * The ingest DSN is read from the environment at use time and never stored on the
 * instance or logged (zero standing secret in artifacts/logs). The only place this
 * backend's name appears is this Driver/ namespace.
 */
#[AsDriver('glitchtip')]
final class GlitchtipErrorSink implements ErrorSinkInterface
{
    public function __construct(
        private readonly BoundedSpool $spool,
        private readonly ErrorTransportInterface $transport,
        private readonly string $ingestUrlEnvVar = 'OBSERVABILITY_GLITCHTIP_DSN',
        private readonly int $drainBatch = 100,
    ) {}

    public function name(): string
    {
        return 'glitchtip';
    }

    /**
     * Best-effort, never throws into the caller: a failure to even spool the error is
     * swallowed (observability must never take down the request that errored).
     */
    public function capture(CapturedError $error): void
    {
        try {
            $this->spool->enqueue(json_encode($error->toArray(), JSON_THROW_ON_ERROR));
        } catch (Throwable) {
            // Intentionally swallowed: a failing error sink must not raise a new error.
        }
    }

    /**
     * Drain spooled errors toward the backend. On a delivery failure the undelivered
     * remainder is re-spooled (FIFO preserved) and the drain stops — never throws.
     */
    public function flush(): void
    {
        $ingestUrl = $this->ingestUrl();
        if ($ingestUrl === null) {
            return; // Not configured → nothing to do; errors stay safely spooled.
        }

        try {
            $records = $this->spool->drain($this->drainBatch);
            foreach ($records as $index => $record) {
                $error = $this->decode($record->payload);
                if ($error !== null && !$this->transport->send($ingestUrl, $error)) {
                    // Re-spool this record and everything after it; preserve order; stop.
                    for ($i = $index, $n = count($records); $i < $n; $i++) {
                        $this->spool->enqueue($records[$i]->payload, $records[$i]->enqueuedAtMs);
                    }

                    return;
                }
            }
        } catch (Throwable) {
            // Best-effort drain: never propagate.
        }
    }

    public function capabilities(): CapabilityDescriptor
    {
        return CapabilityDescriptor::create([
            SinkCapability::OffHost->value => true,
            SinkCapability::DiskBuffering->value => true,
            SinkCapability::OtlpNative->value => false,
            SinkCapability::Tls->value => true,
        ]);
    }

    private function ingestUrl(): ?string
    {
        $value = $_ENV[$this->ingestUrlEnvVar] ?? $_SERVER[$this->ingestUrlEnvVar] ?? getenv($this->ingestUrlEnvVar);
        if (!is_string($value) || $value === '') {
            return null;
        }

        return $value;
    }

    private function decode(string $payload): ?CapturedError
    {
        try {
            /** @var array{exceptionClass?:string, message?:string, severity?:string, context?:array<string,scalar>} $data */
            $data = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        $message = is_string($data['message'] ?? null) ? $data['message'] : '';
        $severity = \Vortos\Observability\Sink\ErrorSeverity::tryFrom(is_string($data['severity'] ?? null) ? $data['severity'] : '')
            ?? \Vortos\Observability\Sink\ErrorSeverity::Error;
        $context = is_array($data['context'] ?? null) ? $data['context'] : [];

        // Already scrubbed when first captured; rebuild without re-scrubbing surprises.
        return CapturedError::fromMessage($message, $severity, $context);
    }
}
