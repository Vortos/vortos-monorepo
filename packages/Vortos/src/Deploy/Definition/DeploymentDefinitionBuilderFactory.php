<?php

declare(strict_types=1);

namespace Vortos\Deploy\Definition;

/**
 * Builds the base {@see DeploymentDefinitionBuilder}, applying the application's config/deploy.php
 * when present.
 *
 * The deploy stack documents config/deploy.php as the deployment configuration surface, but the
 * package historically never loaded it — {@see LayeredDefinitionResolver} used a bare default
 * builder, so operators had to override the service definition to configure anything (upstream
 * P2-1). This factory closes that gap: config/deploy.php returns a closure that receives the
 * default builder and returns a configured one (host/registry/strategy/forEnvironment/...).
 */
final readonly class DeploymentDefinitionBuilderFactory
{
    public function __invoke(string $projectDir): DeploymentDefinitionBuilder
    {
        $builder = new DeploymentDefinitionBuilder();

        $path = rtrim($projectDir, '/') . '/config/deploy.php';
        if ($projectDir === '' || !is_file($path)) {
            return $builder;
        }

        /** @var mixed $config */
        $config = require $path;

        if ($config instanceof \Closure) {
            $result = $config($builder);
            if (!$result instanceof DeploymentDefinitionBuilder) {
                throw new \LogicException(sprintf(
                    'config/deploy.php closure must return a %s, got %s.',
                    DeploymentDefinitionBuilder::class,
                    get_debug_type($result),
                ));
            }

            return $result;
        }

        if ($config instanceof DeploymentDefinitionBuilder) {
            return $config;
        }

        throw new \LogicException(sprintf(
            'config/deploy.php must return a Closure(%1$s): %1$s or a %1$s instance, got %2$s.',
            DeploymentDefinitionBuilder::class,
            get_debug_type($config),
        ));
    }
}
