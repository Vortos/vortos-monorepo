<?php

declare(strict_types=1);

namespace Vortos\OpsKit\Driver;

use Psr\Container\ContainerInterface;
use Vortos\OpsKit\Driver\Exception\UnknownDriverException;

/**
 * The reusable base every concern's driver registry extends.
 *
 * Backed by a Symfony ServiceLocator populated at compile time by the concern's
 * {@see DependencyInjection\CollectDriversCompilerPass}. Drivers are lazy: a driver
 * is not instantiated until the first lookup that needs it, so installing ten
 * drivers but selecting one costs nothing for the other nine — and there is zero
 * runtime reflection on the hot path.
 *
 * A concern subclasses this with its name and (optionally) a typed `get()` override:
 *
 *     final class DeployTargetRegistry extends TaggedDriverRegistry
 *     {
 *         public function __construct(ContainerInterface $drivers)
 *         {
 *             parent::__construct('deploy', $drivers);
 *         }
 *
 *         public function target(string $key): DeployTargetInterface
 *         {
 *             return $this->get($key); // contract guaranteed by the collecting pass
 *         }
 *     }
 */
abstract class TaggedDriverRegistry implements DriverRegistryInterface
{
    /**
     * @param string             $concern human-readable concern name, used in error messages
     * @param ContainerInterface $drivers ServiceLocator keyed by driver key → driver service
     */
    public function __construct(
        private readonly string $concern,
        private readonly ContainerInterface $drivers,
    ) {}

    public function get(string $key): object
    {
        if (!$this->drivers->has($key)) {
            throw UnknownDriverException::forKey($this->concern, $key, $this->keys());
        }

        /** @var object */
        return $this->drivers->get($key);
    }

    public function has(string $key): bool
    {
        return $this->drivers->has($key);
    }

    public function keys(): array
    {
        // ServiceLocator exposes its provided services via getProvidedServices().
        if (method_exists($this->drivers, 'getProvidedServices')) {
            /** @var array<string, mixed> $provided */
            $provided = $this->drivers->getProvidedServices();
            $keys = array_map('strval', array_keys($provided));
            sort($keys);

            return $keys;
        }

        return [];
    }

    final protected function concern(): string
    {
        return $this->concern;
    }
}
