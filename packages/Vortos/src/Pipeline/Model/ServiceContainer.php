<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Model;

/**
 * A CI job service container (GitHub Actions `services:` / GitLab `services:`).
 *
 * Real applications run their test suite against Postgres/Redis/Kafka/etc. The pipeline model
 * carries these so the generated workflow spins them up as sidecars — without them the emitted
 * pipeline cannot replace a hand-written ci.yml (upstream P1-3).
 */
final readonly class ServiceContainer
{
    /**
     * @param list<string>          $ports   e.g. ['5432:5432']
     * @param array<string, string> $env     e.g. ['POSTGRES_PASSWORD' => 'test']
     * @param list<string>          $options health-check flags, e.g. ['--health-cmd=pg_isready']
     */
    public function __construct(
        public string $name,
        public string $image,
        public array $ports = [],
        public array $env = [],
        public array $options = [],
    ) {
        if (!preg_match('/^[a-z0-9][a-z0-9_-]*$/i', $name)) {
            throw new \InvalidArgumentException(sprintf('Service container name "%s" is invalid.', $name));
        }

        if ($image === '') {
            throw new \InvalidArgumentException('Service container image must be non-empty.');
        }
    }

    /**
     * @param array{name: string, image: string, ports?: list<string>, env?: array<string,string>, options?: list<string>} $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['name'],
            $data['image'],
            $data['ports'] ?? [],
            $data['env'] ?? [],
            $data['options'] ?? [],
        );
    }
}
