<?php

declare(strict_types=1);

namespace Vortos\Health\Probe\Cert;

final class InMemoryCertInspector implements CertInspectorInterface
{
    /** @var array<string, CertInspectionResult> */
    private array $results = [];

    public function withResult(string $host, int $port, CertInspectionResult $result): self
    {
        $clone = clone $this;
        $clone->results[$this->key($host, $port)] = $result;

        return $clone;
    }

    public function inspect(string $host, int $port = 443): CertInspectionResult
    {
        return $this->results[$this->key($host, $port)] ?? CertInspectionResult::failure('cert_unreachable');
    }

    private function key(string $host, int $port): string
    {
        return $host . ':' . $port;
    }
}
