<?php

declare(strict_types=1);

namespace Vortos\PersistenceMongo\DependencyInjection\Compiler;

use MongoDB\Client;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Vortos\PersistenceMongo\Read\MongoReadRepository;

/**
 * Auto-wires MongoDB\Client and the database name parameter into all subclasses
 * of MongoReadRepository registered in the container.
 *
 * Before this pass, users had to wire each repository manually in services.php:
 *
 *   $services->set(UserReadRepository::class)
 *       ->arg('$client', service(MongoDB\Client::class))
 *       ->arg('$databaseName', '%vortos.persistence.mongo.database_name%')
 *       ->tag('vortos.read_repository');
 *
 * After this pass, the only registration required is:
 *
 *   $services->set(UserReadRepository::class);
 *
 * The pass also adds the 'vortos.read_repository' tag automatically so that
 * SetupPersistenceCommand, MongoTracingCompilerPass, and MongoCursorSecretCompilerPass
 * all discover the repository without any manual tagging.
 *
 * Runs at TYPE_BEFORE_OPTIMIZATION priority 8 — before MongoTracingCompilerPass (0)
 * and MongoCursorSecretCompilerPass (0) so those passes find the tag.
 */
final class MongoReadRepositoryAutowirePass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(Client::class)) {
            // MongoPersistenceExtension was not loaded — nothing to do.
            return;
        }

        foreach ($container->getDefinitions() as $serviceId => $definition) {
            $className = $definition->getClass() ?? $serviceId;

            if (!class_exists($className)) {
                continue;
            }

            if (!is_subclass_of($className, MongoReadRepository::class)) {
                continue;
            }

            // Inject constructor args if not already explicitly set.
            $args = $definition->getArguments();
            if (empty($args)) {
                $definition->setArgument('$client', new Reference(Client::class));
                $definition->setArgument('$databaseName', '%vortos.persistence.mongo.database_name%');
            }

            // Auto-tag so other compiler passes and SetupPersistenceCommand find the repo.
            $tags = $definition->getTags();
            if (!isset($tags['vortos.read_repository'])) {
                $definition->addTag('vortos.read_repository');
            }
        }
    }
}
