<?php

declare(strict_types=1);

namespace Vortos\Health\Probe\Cert;

/**
 * Seam over the TLS handshake — the only I/O in the cert-expiry probe graph. Every
 * failure mode (timeout, unreachable, self-signed, parse error) returns a typed
 * {@see CertInspectionResult} failure with a distinct error code; never throws.
 */
interface CertInspectorInterface
{
    public function inspect(string $host, int $port = 443): CertInspectionResult;
}
