<?php

declare(strict_types=1);

namespace Vortos\Security\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Vortos\Security\Encryption\Attribute\Encrypted;

/**
 * Scans entity/model classes for #[Encrypted] properties at compile time.
 * Stores the field map as a container parameter so EncryptionService
 * can reference it without runtime reflection.
 *
 * The map is available as 'vortos.security.encrypted_fields':
 *   [ClassName => [propertyName, ...], ...]
 */
final class EncryptionCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $encryptedFields = [];

        foreach ($container->getDefinitions() as $definition) {
            $class = $definition->getClass();
            if (!$class || !class_exists($class)) {
                continue;
            }

            $reflection  = new \ReflectionClass($class);
            $classFields = [];

            foreach ($reflection->getProperties() as $property) {
                if (!empty($property->getAttributes(Encrypted::class))) {
                    $classFields[] = $property->getName();
                }
            }

            if (!empty($classFields)) {
                $encryptedFields[$class] = $classFields;
            }
        }

        $container->setParameter('vortos.security.encrypted_fields', $encryptedFields);
    }
}
