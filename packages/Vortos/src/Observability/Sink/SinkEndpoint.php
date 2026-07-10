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
        public ?string $basePath,
    ) {}

    public static function create(
        string $host,
        OtlpProtocol $protocol,
        ?int $port = null,
        bool $tlsEnabled = true,
        ?string $headersEnvRef = null,
        ?string $basePath = null,
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

        // Normalize the optional base path to a single leading slash, no trailing slash
        // (e.g. `otlp`, `/otlp`, `otlp/` all become `/otlp`). Some OTLP gateways —
        // notably Grafana Cloud's HTTP endpoint — require a base path onto which the
        // exporter appends `/v1/{signal}`. An empty/whitespace value normalizes to null.
        if ($basePath !== null) {
            $basePath = trim(trim($basePath), '/');
            $basePath = $basePath === '' ? null : '/' . $basePath;
        }

        return new self($host, $port, $protocol, $tlsEnabled, $headersEnvRef, $basePath);
    }

    /** The `scheme://host[:port][/base-path]` the collector exporter targets. */
    public function dsn(): string
    {
        $scheme = $this->tlsEnabled ? 'https' : 'http';

        // Omit the port when it is the scheme default (443 for https, 80 for http): a public
        // OTLP gateway is addressed as `https://host/otlp`, and pinning `:443` is redundant
        // noise that also diverges from the canonical endpoint operators expect.
        $defaultPort = $this->tlsEnabled ? 443 : 80;
        $authority = $this->port === $defaultPort
            ? $this->host
            : sprintf('%s:%d', $this->host, $this->port);

        return sprintf('%s://%s%s', $scheme, $authority, $this->basePath ?? '');
    }

    /**
     * @return array{host:string, port:int, protocol:string, tlsEnabled:bool, headersEnvRef:string|null, basePath:string|null}
     */
    public function toArray(): array
    {
        return [
            'host' => $this->host,
            'port' => $this->port,
            'protocol' => $this->protocol->value,
            'tlsEnabled' => $this->tlsEnabled,
            'headersEnvRef' => $this->headersEnvRef,
            'basePath' => $this->basePath,
        ];
    }
}
