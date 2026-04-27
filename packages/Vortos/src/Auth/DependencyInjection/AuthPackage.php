<?php
declare(strict_types=1);

namespace Vortos\Auth\DependencyInjection;

use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Vortos\Auth\Audit\Compiler\AuditCompilerPass;
use Vortos\Auth\DependencyInjection\Compiler\AuthDiscoveryPass;
use Vortos\Auth\FeatureAccess\Compiler\FeatureAccessCompilerPass;
use Vortos\Auth\Quota\Compiler\QuotaCompilerPass;
use Vortos\Auth\RateLimit\Compiler\RateLimitCompilerPass;
use Vortos\Auth\TwoFactor\Compiler\TwoFactorCompilerPass;
use Vortos\Foundation\Contract\PackageInterface;

final class AuthPackage implements PackageInterface
{
    public function getContainerExtension(): ?ExtensionInterface
    {
        return new AuthExtension();
    }

    public function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new AuthDiscoveryPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 45);
        $container->addCompilerPass(new RateLimitCompilerPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 40);
        $container->addCompilerPass(new FeatureAccessCompilerPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 40);
        $container->addCompilerPass(new QuotaCompilerPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 40);
        $container->addCompilerPass(new AuditCompilerPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 40);
        $container->addCompilerPass(new TwoFactorCompilerPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 40);
    }
}
