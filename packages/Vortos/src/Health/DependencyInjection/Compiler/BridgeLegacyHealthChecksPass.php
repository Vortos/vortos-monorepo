<?php

declare(strict_types=1);

namespace Vortos\Health\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Vortos\Foundation\Health\Attribute\AsHealthCheck;
use Vortos\Foundation\Health\Contract\HealthCheckInterface;
use Vortos\Health\Probe\Bridge\LegacyHealthCheckProbe;

final class BridgeLegacyHealthChecksPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $seen = [];

        foreach ($container->getDefinitions() as $id => $definition) {
            $class = $definition->getClass();

            if ($class === null || !class_exists($class)) {
                continue;
            }

            if (!is_subclass_of($class, HealthCheckInterface::class)) {
                continue;
            }

            $ref = new \ReflectionClass($class);
            $attributes = $ref->getAttributes(AsHealthCheck::class);

            if ($attributes === []) {
                continue;
            }

            /** @var AsHealthCheck $attr */
            $attr = $attributes[0]->newInstance();

            $checkName = $this->deriveCheckName($class);

            if (isset($seen[$checkName])) {
                continue;
            }
            $seen[$checkName] = true;

            $bridgeKey = 'legacy-' . $checkName;
            $bridgeId = 'vortos.health.bridge.' . $checkName;

            $bridgeDef = new Definition(LegacyHealthCheckProbe::class);
            $bridgeDef->setArgument('$delegate', new Reference($id));
            $bridgeDef->setArgument('$driverKey', $bridgeKey);
            $bridgeDef->setArgument('$critical', $attr->critical);
            $bridgeDef->addTag(CollectHealthProbesPass::TAG, ['key' => $bridgeKey]);
            $bridgeDef->setPublic(false);

            $container->setDefinition($bridgeId, $bridgeDef);
        }
    }

    private function deriveCheckName(string $class): string
    {
        $parts = explode('\\', $class);
        $short = end($parts);

        $name = (string) preg_replace('/HealthCheck$/', '', $short);
        $name = strtolower((string) preg_replace('/[A-Z]/', '-$0', lcfirst($name)));

        return trim($name, '-') ?: 'unknown';
    }
}
