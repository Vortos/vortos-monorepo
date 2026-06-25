<?php

declare(strict_types=1);

namespace Vortos\Security\SupplyChain\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Vortos\Security\SupplyChain\Driver\Cosign\CosignArtifactSigner;
use Vortos\Security\SupplyChain\Driver\Cisa\CisaKevCatalogProvider;
use Vortos\Security\SupplyChain\Driver\InMemory\InMemoryArtifactSigner;
use Vortos\Security\SupplyChain\Driver\InMemory\InMemoryKevCatalogProvider;
use Vortos\Security\SupplyChain\Driver\InMemory\InMemorySbomGenerator;
use Vortos\Security\SupplyChain\Driver\InMemory\InMemoryVulnerabilityScanner;
use Vortos\Security\SupplyChain\Driver\Null\NullArtifactSigner;
use Vortos\Security\SupplyChain\Driver\Null\NullKevCatalogProvider;
use Vortos\Security\SupplyChain\Driver\Null\NullSbomGenerator;
use Vortos\Security\SupplyChain\Driver\Null\NullVulnerabilityScanner;
use Vortos\Security\SupplyChain\Driver\Process\ProcessRunnerInterface;
use Vortos\Security\SupplyChain\Driver\Process\SymfonyProcessRunner;
use Vortos\Security\SupplyChain\Driver\Syft\SyftSbomGenerator;
use Vortos\Security\SupplyChain\Driver\Trivy\TrivyVulnerabilityScanner;
use Vortos\Security\SupplyChain\Integration\Deploy\AttestationImageSigner;
use Vortos\Security\SupplyChain\Integration\Deploy\SignatureVerificationCheck;
use Vortos\Security\SupplyChain\Port\ArtifactSignerInterface;
use Vortos\Security\SupplyChain\Port\ArtifactSignerRegistry;
use Vortos\Security\SupplyChain\Port\KevCatalogProviderInterface;
use Vortos\Security\SupplyChain\Port\KevCatalogProviderRegistry;
use Vortos\Security\SupplyChain\Port\SbomGeneratorInterface;
use Vortos\Security\SupplyChain\Port\SbomGeneratorRegistry;
use Vortos\Security\SupplyChain\Port\VulnerabilityScannerInterface;
use Vortos\Security\SupplyChain\Port\VulnerabilityScannerRegistry;
use Vortos\Security\SupplyChain\Service\AttestationAssembler;
use Vortos\Security\SupplyChain\Service\AttestationChainVerifier;
use Vortos\Security\SupplyChain\Service\CveGate;
use Vortos\Security\SupplyChain\Service\RuntimeCveWatcher;
use Vortos\Security\SupplyChain\Service\SecretHygieneAuditor;
use Vortos\Security\SupplyChain\Service\SupplyChainManifestDecorator;

final class SupplyChainExtension
{
    public function load(ContainerBuilder $container): void
    {
        $this->registerProcessRunner($container);
        $this->registerRegistries($container);
        $this->registerNullDrivers($container);
        $this->registerInMemoryDrivers($container);
        $this->registerRealDrivers($container);
        $this->registerServices($container);
        $this->registerIntegrations($container);

        $container->registerForAutoconfiguration(SbomGeneratorInterface::class)
            ->addTag('vortos.supply_chain.sbom');
        $container->registerForAutoconfiguration(ArtifactSignerInterface::class)
            ->addTag('vortos.supply_chain.signer');
        $container->registerForAutoconfiguration(VulnerabilityScannerInterface::class)
            ->addTag('vortos.supply_chain.scanner');
        $container->registerForAutoconfiguration(KevCatalogProviderInterface::class)
            ->addTag('vortos.supply_chain.kev');
    }

    private function registerProcessRunner(ContainerBuilder $container): void
    {
        $container->register(SymfonyProcessRunner::class, SymfonyProcessRunner::class)
            ->setShared(true)->setPublic(false);

        $container->setAlias(ProcessRunnerInterface::class, SymfonyProcessRunner::class)
            ->setPublic(false);
    }

    private function registerRegistries(ContainerBuilder $container): void
    {
        $container->register('vortos.supply_chain.sbom_locator', \Symfony\Component\DependencyInjection\ServiceLocator::class)
            ->setArguments([[]])->setPublic(false);
        $container->register(SbomGeneratorRegistry::class, SbomGeneratorRegistry::class)
            ->setArguments([new Reference('vortos.supply_chain.sbom_locator')])
            ->setShared(true)->setPublic(true);

        $container->register('vortos.supply_chain.signer_locator', \Symfony\Component\DependencyInjection\ServiceLocator::class)
            ->setArguments([[]])->setPublic(false);
        $container->register(ArtifactSignerRegistry::class, ArtifactSignerRegistry::class)
            ->setArguments([new Reference('vortos.supply_chain.signer_locator')])
            ->setShared(true)->setPublic(true);

        $container->register('vortos.supply_chain.scanner_locator', \Symfony\Component\DependencyInjection\ServiceLocator::class)
            ->setArguments([[]])->setPublic(false);
        $container->register(VulnerabilityScannerRegistry::class, VulnerabilityScannerRegistry::class)
            ->setArguments([new Reference('vortos.supply_chain.scanner_locator')])
            ->setShared(true)->setPublic(true);

        $container->register('vortos.supply_chain.kev_locator', \Symfony\Component\DependencyInjection\ServiceLocator::class)
            ->setArguments([[]])->setPublic(false);
        $container->register(KevCatalogProviderRegistry::class, KevCatalogProviderRegistry::class)
            ->setArguments([new Reference('vortos.supply_chain.kev_locator')])
            ->setShared(true)->setPublic(true);
    }

    private function registerNullDrivers(ContainerBuilder $container): void
    {
        $devSkip = $container->hasParameter('vortos.supply_chain.dev_unsafe_skip_verification')
            && $container->getParameter('vortos.supply_chain.dev_unsafe_skip_verification');

        $container->register(NullSbomGenerator::class, NullSbomGenerator::class)
            ->addTag('vortos.supply_chain.sbom', ['key' => 'null'])
            ->setShared(true)->setPublic(false);
        $container->register(NullArtifactSigner::class, NullArtifactSigner::class)
            ->setArgument('$devUnsafeSkipVerification', $devSkip)
            ->addTag('vortos.supply_chain.signer', ['key' => 'null'])
            ->setShared(true)->setPublic(false);
        $container->register(NullVulnerabilityScanner::class, NullVulnerabilityScanner::class)
            ->addTag('vortos.supply_chain.scanner', ['key' => 'null'])
            ->setShared(true)->setPublic(false);
        $container->register(NullKevCatalogProvider::class, NullKevCatalogProvider::class)
            ->addTag('vortos.supply_chain.kev', ['key' => 'null'])
            ->setShared(true)->setPublic(false);
    }

    private function registerInMemoryDrivers(ContainerBuilder $container): void
    {
        $container->register(InMemorySbomGenerator::class, InMemorySbomGenerator::class)
            ->addTag('vortos.supply_chain.sbom', ['key' => 'in-memory'])
            ->setShared(true)->setPublic(false);
        $container->register(InMemoryArtifactSigner::class, InMemoryArtifactSigner::class)
            ->addTag('vortos.supply_chain.signer', ['key' => 'in-memory'])
            ->setShared(true)->setPublic(false);
        $container->register(InMemoryVulnerabilityScanner::class, InMemoryVulnerabilityScanner::class)
            ->addTag('vortos.supply_chain.scanner', ['key' => 'in-memory'])
            ->setShared(true)->setPublic(false);
        $container->register(InMemoryKevCatalogProvider::class, InMemoryKevCatalogProvider::class)
            ->addTag('vortos.supply_chain.kev', ['key' => 'in-memory'])
            ->setShared(true)->setPublic(false);
    }

    private function registerRealDrivers(ContainerBuilder $container): void
    {
        $container->register(SyftSbomGenerator::class, SyftSbomGenerator::class)
            ->setArgument('$processRunner', new Reference(ProcessRunnerInterface::class))
            ->addTag('vortos.supply_chain.sbom', ['key' => 'syft'])
            ->setShared(true)->setPublic(false);
        $container->register(TrivyVulnerabilityScanner::class, TrivyVulnerabilityScanner::class)
            ->setArgument('$processRunner', new Reference(ProcessRunnerInterface::class))
            ->addTag('vortos.supply_chain.scanner', ['key' => 'trivy'])
            ->setShared(true)->setPublic(false);
        $container->register(CosignArtifactSigner::class, CosignArtifactSigner::class)
            ->setArgument('$processRunner', new Reference(ProcessRunnerInterface::class))
            ->addTag('vortos.supply_chain.signer', ['key' => 'cosign'])
            ->setShared(true)->setPublic(false);
        $container->register(CisaKevCatalogProvider::class, CisaKevCatalogProvider::class)
            ->setArgument('$processRunner', new Reference(ProcessRunnerInterface::class))
            ->addTag('vortos.supply_chain.kev', ['key' => 'cisa'])
            ->setShared(true)->setPublic(false);
    }

    private function registerServices(ContainerBuilder $container): void
    {
        $container->register(AttestationAssembler::class, AttestationAssembler::class)
            ->setShared(true)->setPublic(true);
        $container->register(AttestationChainVerifier::class, AttestationChainVerifier::class)
            ->setShared(true)->setPublic(true);
        $container->register(CveGate::class, CveGate::class)
            ->setShared(true)->setPublic(true);
        $container->register(RuntimeCveWatcher::class, RuntimeCveWatcher::class)
            ->setShared(true)->setPublic(true);
        $container->register(SecretHygieneAuditor::class, SecretHygieneAuditor::class)
            ->setShared(true)->setPublic(true);
        $container->register(SupplyChainManifestDecorator::class, SupplyChainManifestDecorator::class)
            ->setShared(true)->setPublic(true);
    }

    private function registerIntegrations(ContainerBuilder $container): void
    {
        if (interface_exists(\Vortos\Deploy\Oci\ImageSignerInterface::class)) {
            $container->register(AttestationImageSigner::class, AttestationImageSigner::class)
                ->setArgument('$signerRegistry', new Reference(ArtifactSignerRegistry::class))
                ->setArgument('$signerKey', $container->hasParameter('vortos.supply_chain.signer') ? $container->getParameter('vortos.supply_chain.signer') : 'null')
                ->setShared(true)->setPublic(false);

            $container->register(SignatureVerificationCheck::class, SignatureVerificationCheck::class)
                ->setArgument('$signerRegistry', new Reference(ArtifactSignerRegistry::class))
                ->setArgument('$signerKey', $container->hasParameter('vortos.supply_chain.signer') ? $container->getParameter('vortos.supply_chain.signer') : 'null')
                ->setShared(true)->setPublic(false);
        }
    }
}
