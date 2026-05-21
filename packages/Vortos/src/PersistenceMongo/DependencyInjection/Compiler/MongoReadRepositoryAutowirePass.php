<?php

declare(strict_types=1);

namespace Vortos\PersistenceMongo\DependencyInjection\Compiler;

use MongoDB\Client;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Vortos\PersistenceMongo\Read\MongoReadRepository;
use Vortos\PersistenceMongo\Schema\Attribute\MongoCollection;
use Vortos\PersistenceMongo\Schema\MongoIndexAttributeScanner;

/**
 * Auto-wires MongoDB\Client and the database name parameter into all subclasses
 * of MongoReadRepository registered in the container.
 *
 * Also registers each repository class name with MongoIndexAttributeScanner so that
 * vortos:mongo:sync can discover #[MongoIndex] attributes via reflection — no
 * separate provider files needed.
 *
 * Runs at TYPE_BEFORE_OPTIMIZATION priority 8 — before MongoTracingCompilerPass (0)
 * and MongoCursorSecretCompilerPass (0) so those passes find the tag.
 */
final class MongoReadRepositoryAutowirePass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(Client::class)) {
            return;
        }

        $hasScanner = $container->hasDefinition(MongoIndexAttributeScanner::class);

        foreach ($container->getDefinitions() as $serviceId => $definition) {
            $className = $definition->getClass() ?? $serviceId;

            if (!class_exists($className)) {
                continue;
            }

            if (!is_subclass_of($className, MongoReadRepository::class)) {
                continue;
            }

            $collectionName = $this->resolveCollectionName($className);

            $args = $definition->getArguments();
            if (empty($args)) {
                $definition->setArgument('$client', new Reference(Client::class));
                $definition->setArgument('$databaseName', '%vortos.persistence.mongo.database_name%');
            }

            $definition->setArgument('$collectionName', $collectionName);

            $tags = $definition->getTags();
            if (!isset($tags['vortos.read_repository'])) {
                $definition->addTag('vortos.read_repository');
            }

            if ($hasScanner) {
                $container->getDefinition(MongoIndexAttributeScanner::class)
                    ->addMethodCall('addRepositoryClass', [$className]);
            }
        }
    }

    private function resolveCollectionName(string $className): string
    {
        $attrs = (new \ReflectionClass($className))->getAttributes(MongoCollection::class);

        if (empty($attrs)) {
            throw new \LogicException(sprintf(
                '%s must declare a #[MongoCollection(\'name\')] attribute.',
                $className,
            ));
        }

        return $attrs[0]->newInstance()->name;
    }
}
