<?php

declare(strict_types=1);

namespace Vortos\Authorization\Ownership;

use Vortos\Authorization\Ownership\Attribute\Owner;
use Vortos\Authorization\Ownership\Contract\OwnerResolverInterface;

/**
 * Resolves a resource object's owner id, in order:
 *   1. a registered {@see OwnerResolverInterface} whose type the resource is an instanceof;
 *   2. the reflective #[Owner] property/getter on the resource's class.
 *
 * Returns null when ownership cannot be determined — callers (e.g. owns()) then
 * treat the resource as not owned, which is the safe direction.
 *
 * Registered resolvers are populated at compile time by OwnerResolverCompilerPass.
 */
final class OwnerResolverRegistry
{
    /** @var list<OwnerResolverInterface> */
    private array $resolvers;

    /** @var array<class-string, array{kind: 'property'|'method', name: string}|null> */
    private array $reflectiveCache = [];

    /**
     * @param iterable<OwnerResolverInterface> $resolvers
     */
    public function __construct(iterable $resolvers = [])
    {
        $this->resolvers = $resolvers instanceof \Traversable
            ? iterator_to_array($resolvers, false)
            : array_values($resolvers);
    }

    public function ownerId(object $resource): ?string
    {
        foreach ($this->resolvers as $resolver) {
            $type = $resolver->resourceType();

            if ($resource instanceof $type) {
                return $this->normalize($resolver->ownerId($resource));
            }
        }

        return $this->normalize($this->reflectiveOwnerId($resource));
    }

    private function reflectiveOwnerId(object $resource): mixed
    {
        $accessor = $this->reflectiveAccessor($resource::class);

        if ($accessor === null) {
            return null;
        }

        if ($accessor['kind'] === 'property') {
            $property = new \ReflectionProperty($resource, $accessor['name']);

            return $property->isInitialized($resource) ? $property->getValue($resource) : null;
        }

        return (new \ReflectionMethod($resource, $accessor['name']))->invoke($resource);
    }

    /**
     * @param class-string $class
     * @return array{kind: 'property'|'method', name: string}|null
     */
    private function reflectiveAccessor(string $class): ?array
    {
        if (array_key_exists($class, $this->reflectiveCache)) {
            return $this->reflectiveCache[$class];
        }

        $reflection = new \ReflectionClass($class);

        foreach ($reflection->getProperties() as $property) {
            if ($property->getAttributes(Owner::class) !== []) {
                return $this->reflectiveCache[$class] = ['kind' => 'property', 'name' => $property->getName()];
            }
        }

        foreach ($reflection->getMethods() as $method) {
            if ($method->getNumberOfRequiredParameters() === 0 && $method->getAttributes(Owner::class) !== []) {
                return $this->reflectiveCache[$class] = ['kind' => 'method', 'name' => $method->getName()];
            }
        }

        return $this->reflectiveCache[$class] = null;
    }

    private function normalize(mixed $ownerId): ?string
    {
        if ($ownerId === null) {
            return null;
        }

        if (is_string($ownerId)) {
            return $ownerId;
        }

        if (is_int($ownerId) || (is_object($ownerId) && method_exists($ownerId, '__toString'))) {
            return (string) $ownerId;
        }

        return null;
    }
}
