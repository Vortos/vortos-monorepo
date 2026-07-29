<?php

declare(strict_types=1);

namespace Vortos\Metrics\AutoInstrumentation;

use Doctrine\DBAL\Configuration;
use Doctrine\ORM\EntityManager;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Vortos\Metrics\Telemetry\FrameworkTelemetry;

/**
 * Injects PersistenceMetricsDecorator into the DBAL middleware stack.
 *
 * There are two stacks, not one, and an app can be running the second while this pass only knew
 * about the first.
 *
 * The DBAL path appends to the `Configuration::setMiddlewares()` call added by
 * DbalPersistenceExtension. That is correct — but when vortos-persistence-orm is also installed it
 * re-registers `Doctrine\DBAL\Connection` as `EntityManager::getConnection()`, so the connection
 * the application actually uses is built by the ORM and never sees that Configuration at all. The
 * DBAL one is then referenced by nothing and dropped from the compiled container, taking the whole
 * middleware stack with it. PersistenceOrmExtension's own docblock warns the two packages register
 * conflicting Connection aliases; this is what that costs in practice.
 *
 * The result was silent: `db_queries_total` and `db_query_duration_ms` were never emitted on any
 * ORM install, and the panels built on them read "No data" indistinguishably from a broken
 * exporter. Tracing and slow-query logging ride the same stack and were equally dead.
 *
 * So both paths are wired, mirroring {@see \Vortos\PersistenceDbal\N1Detection\N1DetectionCompilerPass},
 * which already had to solve exactly this.
 *
 * Only runs when:
 *   - FrameworkTelemetry is registered (metrics module is active)
 *   - the persistence module is not disabled
 *   - at least one of the DBAL Configuration / ORM EntityManager definitions exists
 */
final class PersistenceMetricsCompilerPass implements CompilerPassInterface
{
    /** EntityManagerFactory::fromDsn($dsn, $entityPaths, $devMode, $metadataCache, $middlewares) */
    private const ORM_MIDDLEWARE_ARG_INDEX = 4;

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(FrameworkTelemetry::class)) {
            return;
        }

        $disabled = $container->hasParameter('vortos.metrics.disabled_modules')
            ? $container->getParameter('vortos.metrics.disabled_modules')
            : [];

        if (in_array('persistence', $disabled, true)) {
            return;
        }

        $hasDbal = $container->hasDefinition(Configuration::class);
        $hasOrm  = $container->hasDefinition(EntityManager::class);

        if (!$hasDbal && !$hasOrm) {
            return;
        }

        $container->register(PersistenceMetricsDecorator::class, PersistenceMetricsDecorator::class)
            ->setArgument('$telemetry', new Reference(FrameworkTelemetry::class))
            ->setShared(true)
            ->setPublic(false);

        if ($hasDbal) {
            $this->injectIntoDbal($container);
        }

        if ($hasOrm) {
            $this->injectIntoOrm($container);
        }
    }

    private function injectIntoDbal(ContainerBuilder $container): void
    {
        $configDef = $container->getDefinition(Configuration::class);
        $calls     = $configDef->getMethodCalls();

        foreach ($calls as $i => [$method, $args]) {
            if ($method === 'setMiddlewares' && isset($args[0]) && is_array($args[0])) {
                $args[0][] = new Reference(PersistenceMetricsDecorator::class);
                $calls[$i] = [$method, $args];
                $configDef->setMethodCalls($calls);

                return;
            }
        }

        // setMiddlewares was not yet called — add it fresh (DbalPersistencePackage absent).
        $configDef->addMethodCall('setMiddlewares', [[new Reference(PersistenceMetricsDecorator::class)]]);
    }

    private function injectIntoOrm(ContainerBuilder $container): void
    {
        $emDef = $container->getDefinition(EntityManager::class);
        $args  = $emDef->getArguments();

        // Pad to the middleware slot when optional earlier arguments were omitted.
        while (count($args) < self::ORM_MIDDLEWARE_ARG_INDEX) {
            $args[] = null;
        }

        // Append rather than assign. More than one pass injects here — N1 detection does too — and
        // whichever ran last would otherwise silently discard the other's middleware.
        $existing = $args[self::ORM_MIDDLEWARE_ARG_INDEX] ?? [];

        if (!is_array($existing)) {
            $existing = [];
        }

        $existing[] = new Reference(PersistenceMetricsDecorator::class);
        $args[self::ORM_MIDDLEWARE_ARG_INDEX] = $existing;

        $emDef->setArguments($args);
    }
}
