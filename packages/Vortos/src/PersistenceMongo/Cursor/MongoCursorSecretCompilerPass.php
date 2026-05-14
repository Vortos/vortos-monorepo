<?php

declare(strict_types=1);

namespace Vortos\PersistenceMongo\Cursor;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Injects the cursor HMAC secret into all tagged read repositories.
 *
 * All services tagged 'vortos.read_repository' receive a setCursorSecret() call
 * at compile time. The secret is read from the VORTOS_CURSOR_SECRET environment
 * variable. When empty, cursor signing is skipped and only structural validation
 * is applied — configure the secret in production to enable full HMAC protection.
 */
final class MongoCursorSecretCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter('vortos.persistence.mongo.cursor_secret')) {
            return;
        }

        $taggedServices = $container->findTaggedServiceIds('vortos.read_repository');

        foreach (array_keys($taggedServices) as $serviceId) {
            $container->getDefinition($serviceId)
                ->addMethodCall('setCursorSecret', ['%vortos.persistence.mongo.cursor_secret%']);
        }
    }
}
