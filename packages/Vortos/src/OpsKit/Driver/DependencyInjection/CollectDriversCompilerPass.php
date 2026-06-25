<?php

declare(strict_types=1);

namespace Vortos\OpsKit\Driver\DependencyInjection;

use ReflectionClass;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Vortos\OpsKit\Attribute\AsDriver;
use Vortos\OpsKit\Driver\Exception\DriverCollectionException;

/**
 * The reusable collecting pass behind every concern's registry.
 *
 * A concern subclasses this with its tag, registry service id and concern name:
 *
 *     final class CollectDeployTargetsPass extends CollectDriversCompilerPass
 *     {
 *         public function __construct()
 *         {
 *             parent::__construct('vortos.deploy.target', 'vortos.deploy.target_locator', 'deploy');
 *         }
 *     }
 *
 * It finds every service tagged with the concern's tag, reads each driver's key
 * from its #[AsDriver] attribute (compile-time reflection only — never at runtime,
 * Golden Rule #1), validates it, and injects a `key => Reference` map into the
 * concern's ServiceLocator. Everything that can be wrong — a missing key, a
 * malformed key, two drivers claiming one key — fails HERE, at build time.
 *
 * Registered at a deliberately LOW priority (see {@see register()}), so every
 * concern's drivers are already tagged before collection runs, regardless of the
 * order in which packages booted.
 */
abstract class CollectDriversCompilerPass implements CompilerPassInterface
{
    /** Driver keys must be lower-kebab: a letter, then letters/digits/hyphens. */
    private const KEY_PATTERN = '/^[a-z][a-z0-9-]*$/';

    public function __construct(
        private readonly string $tag,
        private readonly string $registryServiceId,
        private readonly string $concern,
    ) {}

    /**
     * Convenience for a concern's Package::build(): adds this pass at the low
     * priority that guarantees all tags exist before collection.
     */
    public static function register(ContainerBuilder $container, self $pass): void
    {
        $container->addCompilerPass(
            $pass,
            \Symfony\Component\DependencyInjection\Compiler\PassConfig::TYPE_BEFORE_OPTIMIZATION,
            -32,
        );
    }

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition($this->registryServiceId)) {
            // The concern did not register its locator (e.g. package not fully loaded).
            // Nothing to collect into — leave it to the concern to fail if required.
            return;
        }

        $map = [];
        $keyOwners = [];

        foreach ($container->findTaggedServiceIds($this->tag) as $serviceId => $tags) {
            $key = $this->resolveKey($container, $serviceId, $tags);

            if (isset($keyOwners[$key])) {
                throw DriverCollectionException::duplicateKey(
                    $this->concern,
                    $key,
                    (string) $keyOwners[$key],
                    $serviceId,
                );
            }

            $keyOwners[$key] = $serviceId;
            $map[$key] = new Reference($serviceId);
        }

        $container->getDefinition($this->registryServiceId)->setArgument(0, $map);
    }

    /**
     * @param array<int, array<string, mixed>> $tags
     */
    private function resolveKey(ContainerBuilder $container, string $serviceId, array $tags): string
    {
        // Prefer an explicit key on the tag, then fall back to the #[AsDriver] attribute.
        foreach ($tags as $tag) {
            if (isset($tag['key']) && is_string($tag['key']) && $tag['key'] !== '') {
                return $this->validateKey($serviceId, $tag['key']);
            }
        }

        $className = $container->getDefinition($serviceId)->getClass();
        if ($className === null || !class_exists($className)) {
            throw DriverCollectionException::missingClass($this->concern, $serviceId);
        }

        $attributes = (new ReflectionClass($className))->getAttributes(AsDriver::class);
        if ($attributes === []) {
            throw DriverCollectionException::missingKey($this->concern, $serviceId);
        }

        /** @var AsDriver $asDriver */
        $asDriver = $attributes[0]->newInstance();

        return $this->validateKey($serviceId, $asDriver->key);
    }

    private function validateKey(string $serviceId, string $key): string
    {
        if ($key === '') {
            throw DriverCollectionException::missingKey($this->concern, $serviceId);
        }

        if (preg_match(self::KEY_PATTERN, $key) !== 1) {
            throw DriverCollectionException::malformedKey($this->concern, $serviceId, $key);
        }

        return $key;
    }
}
