<?php

declare(strict_types=1);

namespace Vortos\Security\DependencyInjection;

use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Vortos\Foundation\Contract\PackageInterface;
use Vortos\OpsKit\Driver\DependencyInjection\CollectDriversCompilerPass;
use Vortos\Security\DependencyInjection\Compiler\CorsPreflightCompilerPass;
use Vortos\Security\DependencyInjection\Compiler\CsrfCompilerPass;
use Vortos\Security\DependencyInjection\Compiler\EncryptionCompilerPass;
use Vortos\Security\DependencyInjection\Compiler\IpFilterCompilerPass;
use Vortos\Security\DependencyInjection\Compiler\RequestSignatureCompilerPass;
use Vortos\Security\DependencyInjection\Compiler\SecurityHeadersCompilerPass;
use Vortos\Security\SupplyChain\DependencyInjection\Compiler\CollectArtifactSignersPass;
use Vortos\Security\SupplyChain\DependencyInjection\Compiler\CollectKevCatalogProvidersPass;
use Vortos\Security\SupplyChain\DependencyInjection\Compiler\CollectSbomGeneratorsPass;
use Vortos\Security\SupplyChain\DependencyInjection\Compiler\CollectVulnerabilityScannersPass;
use Vortos\Security\SupplyChain\DependencyInjection\Compiler\SupplyChainIntegrationPass;

final class SecurityPackage implements PackageInterface
{
    public function getContainerExtension(): ?ExtensionInterface
    {
        return new SecurityExtension();
    }

    public function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new SecurityHeadersCompilerPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 40);
        $container->addCompilerPass(new IpFilterCompilerPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 40);
        $container->addCompilerPass(new CsrfCompilerPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 40);
        $container->addCompilerPass(new RequestSignatureCompilerPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 40);
        $container->addCompilerPass(new EncryptionCompilerPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 40);
        // Must run after RouteCompilerPass (priority 80) so the RouteCollection definition exists.
        $container->addCompilerPass(new CorsPreflightCompilerPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 70);

        CollectDriversCompilerPass::register($container, new CollectSbomGeneratorsPass());
        CollectDriversCompilerPass::register($container, new CollectArtifactSignersPass());
        CollectDriversCompilerPass::register($container, new CollectVulnerabilityScannersPass());
        CollectDriversCompilerPass::register($container, new CollectKevCatalogProvidersPass());
        $container->addCompilerPass(new SupplyChainIntegrationPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, -64);
    }
}
