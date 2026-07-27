<?php

declare(strict_types=1);

namespace Vortos\Scheduler\DependencyInjection\Compiler;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Reference;
use Vortos\Scheduler\Security\NullSchedulePolicy;
use Vortos\Scheduler\Security\SchedulerPermissionCatalog;
use Vortos\Scheduler\Security\SchedulePolicy;
use Vortos\Scheduler\Security\SchedulePolicyInterface;
use Vortos\Scheduler\Security\SchedulerResourcePolicy;

/**
 * Chooses the scheduler's RBAC policy after every extension has loaded.
 *
 * SchedulerExtension::load() decided this with
 *
 *     class_exists($policyEngineClass) && $container->hasDefinition($policyEngineClass)
 *
 * where the class is vortos-authorization's PolicyEngine. class_exists() answers "is
 * vortos-authorization installed?" and is order-free; hasDefinition() answers "has its extension
 * registered the engine YET?" and during load() is a race. Losing it silently aliased
 * SchedulePolicyInterface to NullSchedulePolicy — the scheduler's authorisation checks degrade to
 * permitting everything, on a deployment that has authorization installed and configured.
 *
 * A security control that fails OPEN because of extension ordering is the worst version of this
 * defect: nothing errors, nothing logs, and every schedule operation is simply unauthorised-but-
 * allowed. Resolving it in a compiler pass makes the question answerable.
 *
 * The dynamic class name is also why the architecture ratchet never caught this: it matches literal
 * `X::class` references, and a string in a variable is invisible to it.
 */
final class SchedulePolicyWiringPass implements CompilerPassInterface
{
    /** vortos-authorization's engine. A string because that package is an optional dependency. */
    private const POLICY_ENGINE = 'Vortos\Authorization\Engine\PolicyEngine';

    public function process(ContainerBuilder $container): void
    {
        if ($container->hasAlias(SchedulePolicyInterface::class)
            || $container->hasDefinition(SchedulePolicyInterface::class)
        ) {
            return; // already decided
        }

        if (class_exists(self::POLICY_ENGINE) && $container->has(self::POLICY_ENGINE)) {
            $container->register(SchedulerResourcePolicy::class, SchedulerResourcePolicy::class)
                ->addTag('vortos.policy', ['resource' => 'scheduler'])
                ->setPublic(false);

            $container->register(SchedulerPermissionCatalog::class, SchedulerPermissionCatalog::class)
                ->addTag('vortos.permission_catalog', ['resource' => 'scheduler'])
                ->setPublic(false);

            $container->register(SchedulePolicy::class, SchedulePolicy::class)
                ->setArgument('$policyEngine', new Reference(self::POLICY_ENGINE))
                ->setPublic(false);

            $container->setAlias(SchedulePolicyInterface::class, SchedulePolicy::class);

            return;
        }

        $container->register(NullSchedulePolicy::class, NullSchedulePolicy::class)
            ->setArgument('$logger', new Reference(LoggerInterface::class, ContainerInterface::NULL_ON_INVALID_REFERENCE))
            ->setPublic(false);

        $container->setAlias(SchedulePolicyInterface::class, NullSchedulePolicy::class);
    }
}
