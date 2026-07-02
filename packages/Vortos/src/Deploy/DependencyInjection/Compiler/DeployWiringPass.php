<?php

declare(strict_types=1);

namespace Vortos\Deploy\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Vortos\Deploy\Audit\DeployAuditRecorder;
use Vortos\Deploy\Definition\LayeredDefinitionResolver;
use Vortos\Deploy\Plan\DeployPreflightStateBuilder;
use Vortos\Deploy\Plan\PlanRenderer;
use Vortos\Deploy\Preflight\PreflightContextFactory;
use Vortos\Deploy\Rollback\RollbackGuard;
use Vortos\Deploy\Runner\DeployRunner;
use Vortos\Deploy\Runner\RollbackRunner;
use Vortos\Deploy\State\ContractSoakLedgerInterface;
use Vortos\Deploy\State\CurrentReleaseStoreInterface;
use Vortos\Deploy\Contract\ManualReadiness;
use Vortos\Deploy\Target\DeployTargetRegistry;
use Vortos\Migration\Schema\MigrationPhaseReaderInterface;
use Vortos\Release\Migration\AppliedMigrationSetReaderInterface;
use Vortos\Release\ReadModel\ManifestReadModelInterface;

/**
 * Wires the deploy services that have hard cross-package dependencies (vortos-release read
 * model, vortos-migration applied-set / phase readers).
 *
 * These decisions were historically made inside DeployExtension::load() with
 * $container->has(<foreign service>), which is unreliable: load() runs during
 * MergeExtensionConfigurationPass while other extensions have not necessarily loaded yet, so
 * the checks were effectively always false and the deploy runners/preflight silently never
 * registered. A compiler pass runs after every extension's load(), so has() here reflects the
 * fully-merged container and is order-independent.
 *
 * The console commands (deploy, deploy:doctor, deploy:rollback) are NOT registered here — they
 * register unconditionally in load() with their runner/factory injected as
 * NULL_ON_INVALID_REFERENCE and fail loudly at runtime with remediation when the stack below
 * is absent. That keeps them visible in the console command list regardless of what is installed.
 */
final class DeployWiringPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $hasApplied  = $container->has(AppliedMigrationSetReaderInterface::class);
        $hasManifest = $container->has(ManifestReadModelInterface::class);
        $hasPhase    = $container->has(MigrationPhaseReaderInterface::class);

        // ── Rollback guard (release manifest + applied migration set) ──
        if ($hasApplied && $hasManifest) {
            $container->register(RollbackGuard::class, RollbackGuard::class)
                ->setArgument('$appliedReader', new Reference(AppliedMigrationSetReaderInterface::class))
                ->setArgument('$manifestReadModel', new Reference(ManifestReadModelInterface::class))
                ->setPublic(false);
        }

        // ── Preflight state builder (applied set + migration phase) ──
        if ($hasApplied && $hasPhase) {
            $container->register(DeployPreflightStateBuilder::class, DeployPreflightStateBuilder::class)
                ->setArgument('$appliedReader', new Reference(AppliedMigrationSetReaderInterface::class))
                ->setArgument('$phaseReader', new Reference(MigrationPhaseReaderInterface::class))
                ->setArgument('$contractReadiness', new Reference(ManualReadiness::class))
                ->setArgument('$soakLedger', new Reference(ContractSoakLedgerInterface::class))
                ->setArgument('$releaseStore', new Reference(CurrentReleaseStoreInterface::class))
                ->setPublic(false);
        }

        // ── Preflight context factory (needs the state builder + manifest read model) ──
        $hasContextDeps = $container->has(DeployPreflightStateBuilder::class) && $hasManifest;

        if ($hasContextDeps) {
            $container->register(PreflightContextFactory::class, PreflightContextFactory::class)
                ->setArgument('$resolver', new Reference(LayeredDefinitionResolver::class))
                ->setArgument('$manifestReadModel', new Reference(ManifestReadModelInterface::class))
                ->setArgument('$stateBuilder', new Reference(DeployPreflightStateBuilder::class))
                ->setArgument('$targets', new Reference(DeployTargetRegistry::class))
                ->setPublic(false);
        }

        // ── Runners (deploy + rollback) — need the context factory + rollback guard ──
        if ($hasContextDeps && $container->has(RollbackGuard::class)) {
            $container->register(RollbackRunner::class, RollbackRunner::class)
                ->setArgument('$resolver', new Reference(LayeredDefinitionResolver::class))
                ->setArgument('$manifestReadModel', new Reference(ManifestReadModelInterface::class))
                ->setArgument('$rollbackGuard', new Reference(RollbackGuard::class))
                ->setArgument('$targets', new Reference(DeployTargetRegistry::class))
                ->setArgument('$stateBuilder', new Reference(DeployPreflightStateBuilder::class))
                ->setArgument('$planRenderer', new Reference(PlanRenderer::class))
                ->setArgument('$auditRecorder', new Reference(DeployAuditRecorder::class))
                ->setPublic(false);

            $container->register(DeployRunner::class, DeployRunner::class)
                ->setArgument('$contextFactory', new Reference(PreflightContextFactory::class))
                ->setArgument('$targets', new Reference(DeployTargetRegistry::class))
                ->setArgument('$planRenderer', new Reference(PlanRenderer::class))
                ->setArgument('$doctor', new Reference(\Vortos\Deploy\Preflight\DeployDoctor::class))
                ->setArgument('$rollbackRunner', new Reference(RollbackRunner::class))
                ->setArgument('$auditRecorder', new Reference(DeployAuditRecorder::class))
                ->setPublic(false);
        }
    }
}
