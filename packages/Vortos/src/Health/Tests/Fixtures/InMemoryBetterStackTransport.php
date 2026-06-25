<?php

declare(strict_types=1);

namespace Vortos\Health\Tests\Fixtures;

use Vortos\Health\Uptime\Driver\BetterStack\BetterStackTransportInterface;

final class InMemoryBetterStackTransport implements BetterStackTransportInterface
{
    /** @var array{status: int, body: string}|null */
    private ?array $response = null;

    /** @var list<array{method: string, url: string, headers: array<string,string>, jsonBody: array<string,mixed>|null}> */
    private array $requests = [];

    /** @param array{status: int, body: string} $response */
    public function withResponse(array $response): self
    {
        $clone = clone $this;
        $clone->response = $response;

        return $clone;
    }

    public function request(string $method, string $url, array $headers, ?array $jsonBody): array
    {
        $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'jsonBody' => $jsonBody];

        return $this->response ?? ['status' => 0, 'body' => ''];
    }

    /** @return list<array{method: string, url: string, headers: array<string,string>, jsonBody: array<string,mixed>|null}> */
    public function requests(): array
    {
        return $this->requests;
    }

    public function lastRequest(): ?array
    {
        return $this->requests === [] ? null : $this->requests[array_key_last($this->requests)];
    }
}
