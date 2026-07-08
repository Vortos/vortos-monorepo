<?php

declare(strict_types=1);

namespace Vortos\Http\DependencyInjection\Compiler;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpFoundation\RequestStack;
use Vortos\Http\Controller\ArgumentResolver;
use Vortos\Http\Controller\ControllerResolver;
use Vortos\Http\Kernel;
use Vortos\Http\Pipeline\Pipeline;
use Vortos\Http\Routing\Router;

final class RegisterExceptionHandlerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        // Preserve the terminable-middleware wiring contributed by RegisterTerminablePass
        // (priority 90, runs before this pass). The full Kernel-definition replacement below
        // would otherwise drop it, leaving $terminableMiddleware at its empty default — which
        // silently disables every terminable middleware (OTLP metrics flush, log flush): nothing
        // runs on kernel.terminate and no metrics are ever exported.
        $terminableMiddleware = [];
        if ($container->hasDefinition(Kernel::class)) {
            try {
                $terminableMiddleware = $container->getDefinition(Kernel::class)->getArgument('$terminableMiddleware');
            } catch (\OutOfBoundsException) {
                $terminableMiddleware = [];
            }
        }

        $tagged = $container->findTaggedServiceIds('vortos.exception_handler');

        $handlers = [];
        foreach ($tagged as $id => $tags) {
            $priority = (int) ($tags[0]['priority'] ?? 0);
            $handlers[$id] = $priority;
        }

        arsort($handlers); // highest priority = first to handle

        $references = array_map(
            static fn(string $id): Reference => new Reference($id),
            array_keys($handlers),
        );

        // Symfony's MergeExtensionConfigurationPass restores its pre-extension snapshot via
        // addDefinitions() (line 99 of the pass). The services.php auto-loader registers the
        // Kernel via addContainerExcludedTag() as abstract=true + container.excluded, which ends
        // up in that snapshot. After restoration, ResolveInstanceofConditionalsPass (built-in,
        // priority 100) re-sets every definition. Both happen before this pass (priority 85).
        //
        // Fix: completely replace the definition here so the correct wired Kernel reaches the
        // optimization passes. The extension's load() still registers dependencies (Router,
        // Pipeline, etc.) which are NOT affected by the snapshot restore (only Kernel itself
        // is overridden because it matched the excluded-class scan).
        $kernelDef = (new Definition(Kernel::class))
            ->setArgument('$router', new Reference(Router::class))
            ->setArgument('$controllerResolver', new Reference(ControllerResolver::class))
            ->setArgument('$argumentResolver', new Reference(ArgumentResolver::class))
            ->setArgument('$pipeline', new Reference(Pipeline::class))
            ->setArgument('$requestStack', new Reference(RequestStack::class))
            ->setArgument('$exceptionHandlers', $references)
            ->setArgument('$terminableMiddleware', $terminableMiddleware)
            ->setArgument('$logger', new Reference(LoggerInterface::class, ContainerBuilder::NULL_ON_INVALID_REFERENCE))
            ->setShared(true)
            ->setPublic(true);

        $container->setDefinition(Kernel::class, $kernelDef);

        if ($container->hasAlias('vortos')) {
            $container->getAlias('vortos')->setPublic(true);
        } else {
            $container->setAlias('vortos', Kernel::class)->setPublic(true);
        }
    }
}
