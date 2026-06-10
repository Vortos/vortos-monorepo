<?php

declare(strict_types=1);

namespace Vortos\Messaging\Upcasting;

/**
 * Applies registered upcasters to a decoded payload, walking version steps
 * upward (v1→v2→v3 …) until no further step exists for the wire event.
 *
 * The map shape (compiled from consumer definitions):
 *
 *   [wireName => [fromVersion => upcasterClass]]
 *
 * Upcaster instances are created lazily and cached for the process lifetime —
 * they are stateless by contract.
 */
final class UpcasterChain
{
    /** @var array<class-string, UpcasterInterface> */
    private array $instances = [];

    public function __construct(
        /** @var array<string, array<int, class-string<UpcasterInterface>>> */
        private readonly array $upcasterMap = [],
    ) {}

    public function hasStepsFor(string $wireName): bool
    {
        return isset($this->upcasterMap[$wireName]);
    }

    /**
     * Upcasts the payload from $fromVersion as far as the chain reaches.
     *
     * @param array<string, mixed> $payload
     * @return array{array<string, mixed>, int} [payload, version after upcasting]
     */
    public function upcast(string $wireName, int $fromVersion, array $payload): array
    {
        $steps   = $this->upcasterMap[$wireName] ?? [];
        $version = $fromVersion;

        while (isset($steps[$version])) {
            $class = $steps[$version];
            $this->instances[$class] ??= new $class();
            $payload = $this->instances[$class]->upcast($payload);
            ++$version;
        }

        return [$payload, $version];
    }
}
