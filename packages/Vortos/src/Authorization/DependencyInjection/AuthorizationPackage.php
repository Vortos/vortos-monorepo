<?php
declare(strict_types=1);

namespace Vortos\Authorization\DependencyInjection;

use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Vortos\Authorization\DependencyInjection\Compiler\PermissionRegistryPass;
use Vortos\Authorization\DependencyInjection\Compiler\PolicyRegistryPass;
use Vortos\Authorization\Ownership\Compiler\OwnershipCompilerPass;
use Vortos\Authorization\Scope\Compiler\ScopeResolverCompilerPass;
use Vortos\Foundation\Contract\PackageInterface;

final class AuthorizationPackage implements PackageInterface
{
    public function getContainerExtension(): ?ExtensionInterface
    {
        return new AuthorizationExtension();
    }

    public function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new PermissionRegistryPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 60);
        $container->addCompilerPass(new PolicyRegistryPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 50);
        $container->addCompilerPass(new OwnershipCompilerPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 40);
        $container->addCompilerPass(new ScopeResolverCompilerPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 40);
    }
}
