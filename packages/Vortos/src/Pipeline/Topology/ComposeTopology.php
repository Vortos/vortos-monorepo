<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Topology;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * A compose topology parsed once, for every {@see TopologyRuleInterface} to inspect.
 *
 * One parser for every rule on purpose: two rules reading the same file two different ways is how
 * one of them ends up judging a topology compose would never run.
 */
final readonly class ComposeTopology
{
    /**
     * @param string                       $hostDir  absolute host directory the topology is run from;
     *                                               compose resolves relative paths against it
     * @param array<string, array<mixed>>  $services service name => its definition
     */
    private function __construct(
        public string $hostDir,
        public array $services,
    ) {}

    /** @throws UnreadableTopologyException */
    public static function parse(string $yaml, string $hostDir): self
    {
        if (HostPath::resolve($hostDir, '/') !== rtrim($hostDir, '/') || !str_starts_with($hostDir, '/')) {
            throw new \InvalidArgumentException(sprintf('Topology host dir must be a normalised absolute path, got "%s".', $hostDir));
        }

        try {
            $document = Yaml::parse($yaml, Yaml::PARSE_CUSTOM_TAGS);
        } catch (ParseException $e) {
            throw new UnreadableTopologyException(sprintf('the compose topology does not parse: %s', $e->getMessage()), previous: $e);
        }

        if (!\is_array($document) || !\is_array($document['services'] ?? null)) {
            throw new UnreadableTopologyException('the compose topology has no services mapping');
        }

        $services = [];
        foreach ($document['services'] as $name => $definition) {
            $services[(string) $name] = \is_array($definition) ? $definition : [];
        }

        return new self($hostDir, $services);
    }

    /** The absolute host path compose would use for a path written in this topology, or null if unprovable. */
    public function resolveHostPath(string $path): ?string
    {
        return HostPath::resolve($path, $this->hostDir);
    }
}
