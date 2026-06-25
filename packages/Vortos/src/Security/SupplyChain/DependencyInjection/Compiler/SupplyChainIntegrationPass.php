<?php

declare(strict_types=1);

namespace Vortos\Security\SupplyChain\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Vortos\Security\SupplyChain\Integration\Deploy\AttestationImageSigner;
use Vortos\Security\SupplyChain\Integration\Deploy\SignatureVerificationCheck;

final class SupplyChainIntegrationPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $this->wireDeploy($container);
        $this->wireAlerts($container);
    }

    private function wireDeploy(ContainerBuilder $container): void
    {
        if (!interface_exists(\Vortos\Deploy\Oci\ImageSignerInterface::class)) {
            return;
        }

        $verificationEnabled = $container->hasParameter('vortos.supply_chain.verification_enabled')
            && $container->getParameter('vortos.supply_chain.verification_enabled');

        if ($verificationEnabled && $container->hasDefinition(AttestationImageSigner::class)) {
            $container->setAlias(\Vortos\Deploy\Oci\ImageSignerInterface::class, AttestationImageSigner::class)
                ->setPublic(true);
        }

        if ($container->hasDefinition(SignatureVerificationCheck::class)) {
            $container->getDefinition(SignatureVerificationCheck::class)
                ->addTag('vortos.deploy.preflight_check');
        }
    }

    private function wireAlerts(ContainerBuilder $container): void
    {
        if (!interface_exists(\Vortos\Alerts\AlertDispatcherInterface::class)) {
            return;
        }
    }
}
