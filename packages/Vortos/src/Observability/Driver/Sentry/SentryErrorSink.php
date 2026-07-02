<?php

declare(strict_types=1);

namespace Vortos\Observability\Driver\Sentry;

use Vortos\Observability\Buffer\BoundedSpool;
use Vortos\Observability\Driver\Glitchtip\GlitchtipErrorSink;
use Vortos\Observability\Sink\ErrorTransportInterface;
use Vortos\OpsKit\Attribute\AsDriver;

/**
 * Sentry error sink. Sentry's ingest API is the same envelope protocol GlitchTip implements, so
 * this reuses the spool-then-drain machinery of {@see GlitchtipErrorSink} verbatim and differs
 * only in its driver key ('sentry') and the DSN environment variable it reads
 * (OBSERVABILITY_SENTRY_DSN). Select it with OBSERVABILITY_ERROR_SINK=sentry.
 *
 * The DSN is read from the environment at drain time and never stored on the instance or logged
 * (zero standing secret), identical to the GlitchTip driver.
 */
#[AsDriver('sentry')]
final class SentryErrorSink extends GlitchtipErrorSink
{
    public function __construct(
        BoundedSpool $spool,
        ErrorTransportInterface $transport,
        string $ingestUrlEnvVar = 'OBSERVABILITY_SENTRY_DSN',
        int $drainBatch = 100,
    ) {
        parent::__construct($spool, $transport, $ingestUrlEnvVar, $drainBatch);
    }

    public function name(): string
    {
        return 'sentry';
    }
}
