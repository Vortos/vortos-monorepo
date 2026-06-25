<?php

declare(strict_types=1);

namespace Vortos\Observability\Sink;

use InvalidArgumentException;

/**
 * The off-host destination the local collector exports telemetry to.
 *
 * This is the endpoint the **collector** dials — never the app. The app always emits
 * to the loopback collector; the collector forwards here. A credential, if any, is
 * referenced indirectly via {@see $headersEnvRef} (an `${env:...}` placeholder the
 * collector resolves at runtime) so no token is ever inlined into a committed config
 * (§12.4 / security: zero plaintext secret in artifacts).
 *
 * Readonly + validated at construction so an illegal endpoint is unrepresentable.
 */
final readonly class SinkEndpoint
{
    private function __construct(
        public string $host,
        public int $port,
        public OtlpProtocol $protocol,
        public bool $tlsEnabled,
        public ?string $headersEnvRef,
    ) {}

    public static function create(
        string $host,
        OtlpProtocol $protocol,
        ?int $port = null,
        bool $tlsEnabled = true,
        ?string $headersEnvRef = null,
    ): self {
        $host = trim($host);
        if ($host === '') {
            throw new InvalidArgumentException('Sink endpoint host must be a non-empty string.');
        }

        $port ??= $protocol->defaultPort();
        if ($port < 1 || $port > 65535) {
            throw new InvalidArgumentException("Sink endpoint port must be 1-65535, got {$port}.");
        }

        if ($headersEnvRef !== null && $headersEnvRef === '') {
            throw new InvalidArgumentException('Sink endpoint headersEnvRef, when set, must be a non-empty env var name.');
        }

        return new self($host, $port, $protocol, $tlsEnabled, $headersEnvRef);
    }

    /** The `scheme://host:port` the collector exporter targets. */
    public function dsn(): string
    {
        $scheme = $this->protocol === OtlpProtocol::Grpc
            ? ($this->tlsEnabled ? 'https' : 'http')
            : ($this->tlsEnabled ? 'https' : 'http');

        return sprintf('%s://%s:%d', $scheme, $this->host, $this->port);
    }

    /**
     * @return array{host:string, port:int, protocol:string, tlsEnabled:bool, headersEnvRef:string|null}
     */
    public function toArray(): array
    {
        return [
            'host' => $this->host,
            'port' => $this->port,
            'protocol' => $this->protocol->value,
            'tlsEnabled' => $this->tlsEnabled,
            'headersEnvRef' => $this->headersEnvRef,
        ];
    }
}
