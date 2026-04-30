<?php

declare(strict_types=1);

namespace Vortos\Cqrs\DependencyInjection;

use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Vortos\Foundation\Contract\PackageInterface;
use Vortos\Cqrs\DependencyInjection\Compiler\CommandHandlerPass;
use Vortos\Cqrs\DependencyInjection\Compiler\IdempotencyKeyPass;
use Vortos\Cqrs\DependencyInjection\Compiler\QueryHandlerPass;
use Vortos\Cqrs\DependencyInjection\Compiler\ValidationPass;

/**
 * Compiler pass order:
 *   CommandHandlerPass (50) — builds command handler map
 *   QueryHandlerPass   (50) — builds query handler map
 *   IdempotencyKeyPass (40) — resolves idempotency strategies
 *   ValidationPass     (30) — warns about unconstrained string properties
 */
final class CqrsPackage implements PackageInterface
{
    public function getContainerExtension(): ?ExtensionInterface
    {
        return new CqrsExtension();
    }

    public function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new CommandHandlerPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 50);
        $container->addCompilerPass(new QueryHandlerPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 50);
        $container->addCompilerPass(new IdempotencyKeyPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 40);
        $container->addCompilerPass(new ValidationPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 30);
    }
}
