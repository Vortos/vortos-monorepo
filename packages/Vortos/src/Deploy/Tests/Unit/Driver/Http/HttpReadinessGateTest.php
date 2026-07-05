<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Driver\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Vortos\Deploy\Compose\ColorEndpoint;
use Vortos\Deploy\Driver\Http\HttpReadinessGate;
use Vortos\Deploy\Gate\GateBudget;
use Vortos\Deploy\Target\ActiveColor;

/**
 * GAP-C: the blue-green readiness gate must accept vortos-health's canonical probe vocabulary
 * (`pass`/`warn` on HTTP 200) as ready, keep accepting the generic `ok`/`ready`, and keep polling on
 * `fail`/503. Before the fix it only accepted `ok`/`ready`, so a healthy vortos-health color (which
 * answers `{"status":"pass"}`) could never satisfy the gate.
 */
final class HttpReadinessGateTest extends TestCase
{
    /**
     * @return iterable<string, array{0: int, 1: string, 2: bool}>
     */
    public static function statusCases(): iterable
    {
        yield 'health pass'   => [200, '{"status":"pass"}', true];
        yield 'health warn'   => [200, '{"status":"warn"}', true];
        yield 'generic ok'    => [200, '{"status":"ok"}', true];
        yield 'generic ready' => [200, '{"status":"ready"}', true];
        yield 'health fail'   => [503, '{"status":"fail"}', false];
        yield 'degraded'      => [200, '{"status":"degraded"}', false];
        yield 'non-200 body-ok' => [500, '{"status":"pass"}', false];
    }

    #[DataProvider('statusCases')]
    public function test_gate_readiness_vocabulary(int $httpStatus, string $body, bool $expectedPass): void
    {
        $gate = new HttpReadinessGate(
            new StubClient($httpStatus, $body),
            new StubRequestFactory(),
        );

        $result = $gate->awaitReady(
            ActiveColor::Blue,
            new ColorEndpoint('app-blue', 8080),
            // Single attempt, short budget: a non-ready case returns immediately without sleeping.
            new GateBudget(timeout: 5.0, interval: 0.01, maxAttempts: 1),
        );

        self::assertSame($expectedPass, $result->passed);
    }
}

final class StubClient implements ClientInterface
{
    public function __construct(
        private readonly int $status,
        private readonly string $body,
    ) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        return new StubResponse($this->status, $this->body);
    }
}

final class StubRequestFactory implements RequestFactoryInterface
{
    public function createRequest(string $method, $uri): RequestInterface
    {
        return new StubRequest();
    }
}

final class StubRequest implements RequestInterface
{
    public function getProtocolVersion(): string { return '1.1'; }
    public function withProtocolVersion(string $version): static { return $this; }
    public function getHeaders(): array { return []; }
    public function hasHeader(string $name): bool { return false; }
    public function getHeader(string $name): array { return []; }
    public function getHeaderLine(string $name): string { return ''; }
    public function withHeader(string $name, $value): static { return $this; }
    public function withAddedHeader(string $name, $value): static { return $this; }
    public function withoutHeader(string $name): static { return $this; }
    public function getBody(): StreamInterface { return new StubStream(''); }
    public function withBody(StreamInterface $body): static { return $this; }
    public function getRequestTarget(): string { return '/'; }
    public function withRequestTarget(string $requestTarget): static { return $this; }
    public function getMethod(): string { return 'GET'; }
    public function withMethod(string $method): static { return $this; }
    public function getUri(): \Psr\Http\Message\UriInterface { throw new \LogicException('unused'); }
    public function withUri(\Psr\Http\Message\UriInterface $uri, bool $preserveHost = false): static { return $this; }
}

final class StubResponse implements ResponseInterface
{
    public function __construct(
        private readonly int $status,
        private readonly string $body,
    ) {}

    public function getStatusCode(): int { return $this->status; }
    public function withStatus(int $code, string $reasonPhrase = ''): static { return $this; }
    public function getReasonPhrase(): string { return ''; }
    public function getProtocolVersion(): string { return '1.1'; }
    public function withProtocolVersion(string $version): static { return $this; }
    public function getHeaders(): array { return []; }
    public function hasHeader(string $name): bool { return false; }
    public function getHeader(string $name): array { return []; }
    public function getHeaderLine(string $name): string { return ''; }
    public function withHeader(string $name, $value): static { return $this; }
    public function withAddedHeader(string $name, $value): static { return $this; }
    public function withoutHeader(string $name): static { return $this; }
    public function getBody(): StreamInterface { return new StubStream($this->body); }
    public function withBody(StreamInterface $body): static { return $this; }
}

final class StubStream implements StreamInterface
{
    public function __construct(private readonly string $contents) {}

    public function __toString(): string { return $this->contents; }
    public function close(): void {}
    public function detach() { return null; }
    public function getSize(): ?int { return \strlen($this->contents); }
    public function tell(): int { return 0; }
    public function eof(): bool { return true; }
    public function isSeekable(): bool { return false; }
    public function seek(int $offset, int $whence = \SEEK_SET): void {}
    public function rewind(): void {}
    public function isWritable(): bool { return false; }
    public function write(string $string): int { return 0; }
    public function isReadable(): bool { return true; }
    public function read(int $length): string { return $this->contents; }
    public function getContents(): string { return $this->contents; }
    public function getMetadata(?string $key = null) { return null; }
}
