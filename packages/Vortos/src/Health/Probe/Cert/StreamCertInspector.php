<?php

declare(strict_types=1);

namespace Vortos\Health\Probe\Cert;

/**
 * Real TLS handshake via `stream_socket_client` with `capture_peer_cert` — bounded
 * by a hard connect timeout (§6.2). Verification is intentionally strict
 * (`verify_peer`/`verify_peer_name` on, `allow_self_signed` off): a self-signed cert
 * where a real one is expected is itself the misconfiguration this probe exists to
 * catch, not a value to silently report an expiry date for.
 */
final class StreamCertInspector implements CertInspectorInterface
{
    public function __construct(
        private readonly int $connectTimeoutSeconds = 5,
    ) {}

    public function inspect(string $host, int $port = 443): CertInspectionResult
    {
        if ($host === '' || $port < 1 || $port > 65535) {
            return CertInspectionResult::failure('cert_invalid_target');
        }

        $context = stream_context_create([
            'ssl' => [
                'capture_peer_cert' => true,
                'verify_peer' => true,
                'verify_peer_name' => true,
                'allow_self_signed' => false,
                'SNI_enabled' => true,
                'peer_name' => $host,
            ],
        ]);

        $errno = 0;
        $errstr = '';

        $stream = @stream_socket_client(
            sprintf('ssl://%s:%d', $host, $port),
            $errno,
            $errstr,
            $this->connectTimeoutSeconds,
            STREAM_CLIENT_CONNECT,
            $context,
        );

        if ($stream === false) {
            return CertInspectionResult::failure($this->classifyError($errno, $errstr));
        }

        try {
            $options = stream_context_get_options($context);
            $cert = $options['ssl']['peer_certificate'] ?? null;

            if ($cert === null) {
                return CertInspectionResult::failure('cert_inspection_failed');
            }

            $parsed = openssl_x509_parse($cert);

            if ($parsed === false || !isset($parsed['validTo_time_t'])) {
                return CertInspectionResult::failure('cert_parse_error');
            }

            $daysUntilExpiry = (int) floor(($parsed['validTo_time_t'] - time()) / 86400);

            return CertInspectionResult::ok($daysUntilExpiry);
        } finally {
            fclose($stream);
        }
    }

    private function classifyError(int $errno, string $errstr): string
    {
        if (stripos($errstr, 'self signed') !== false || stripos($errstr, 'self-signed') !== false) {
            return 'cert_self_signed';
        }

        if ($errno === 110 || stripos($errstr, 'timed out') !== false || stripos($errstr, 'timeout') !== false) {
            return 'cert_handshake_timeout';
        }

        return 'cert_unreachable';
    }
}
