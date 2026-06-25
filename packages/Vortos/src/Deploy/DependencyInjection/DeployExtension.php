<?php

declare(strict_types=1);

namespace Vortos\Deploy\DependencyInjection;

use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Vortos\Deploy\Audit\DeployAuditRecorder;
use Vortos\Deploy\Audit\DeployAuditSinkInterface;
use Vortos\Deploy\Console\DeployCommand;
use Vortos\Deploy\Console\DoctorCommand;
use Vortos\Deploy\Console\PullAgentReconcileCommand;
use Vortos\Deploy\Console\RollbackCommand;
use Vortos\Deploy\Canary\CanaryAnalyzerInterface;
use Vortos\Deploy\Canary\CanaryAnalyzerRegistry;
use Vortos\Deploy\Canary\CanaryGate;
use Vortos\Deploy\Canary\Driver\NullCanaryAnalyzer;
use Vortos\Deploy\Canary\Driver\SloPrometheusCanaryAnalyzer;
use Vortos\Deploy\Canary\StatisticalGuard;
use Vortos\Deploy\DependencyInjection\Compiler\CollectCanaryAnalyzersPass;
use Vortos\Deploy\DependencyInjection\Compiler\CollectDeployAuditSinksPass;
use Vortos\Deploy\Definition\DeploymentDefinitionBuilder;
use Vortos\Deploy\Definition\LayeredDefinitionResolver;
use Vortos\Deploy\Preflight\Check\CanaryAnalyzerReadyCheck;
use Vortos\Deploy\Preflight\Check\CapabilityDescriptorCheck;
use Vortos\Deploy\Preflight\Check\CredentialCheck;
use Vortos\Deploy\Preflight\Check\DriverSetCheck;
use Vortos\Deploy\Preflight\Check\SchemaCompatibilityCheck;
use Vortos\Deploy\Preflight\Check\TargetArchCheck;
use Vortos\Deploy\Preflight\DeployDoctor;
use Vortos\Deploy\Preflight\PreflightCheckInterface;
use Vortos\Deploy\Preflight\PreflightContextFactory;
use Vortos\Deploy\Runner\DeployRunner;
use Vortos\Deploy\Runner\RollbackRunner;
use Vortos\Deploy\Compose\ComposeProjectFactory;
use Vortos\Deploy\Credential\CredentialProviderInterface;
use Vortos\Deploy\Credential\CredentialProviderRegistry;
use Vortos\Deploy\Credential\EphemeralKeyPairFactory;
use Vortos\Deploy\Credential\Governance\ChangeRequestDeployApprovalGate;
use Vortos\Deploy\Credential\Governance\DeployApprovalGateInterface;
use Vortos\Deploy\Credential\Governance\DeployChangeRequestStoreInterface;
use Vortos\Deploy\Credential\Governance\NullDeployApprovalGate;
use Vortos\Deploy\Credential\OidcTokenSourceInterface;
use Vortos\Deploy\Credential\PullAgentCredentialProvider;
use Vortos\Deploy\Credential\RegistryTokenExchangeInterface;
use Vortos\Deploy\Credential\SshCaOidcCredentialProvider;
use Vortos\Deploy\Credential\SshCertificateAuthorityInterface;
use Vortos\Deploy\Credential\SshKeyCredentialProvider;
use Vortos\Deploy\Cutover\CutoverCoordinator;
use Vortos\Deploy\Cutover\CutoverEventRecorderInterface;
use Vortos\Deploy\Cutover\EdgeReconciler;
use Vortos\Deploy\Cutover\EdgeRouterInterface;
use Vortos\Deploy\Cutover\EdgeRouterRegistry;
use Vortos\Deploy\Cutover\NullCutoverEventRecorder;
use Vortos\Deploy\Cutover\RateLimitStateStoreInterface;
use Vortos\Deploy\Cutover\ReconcileRateLimiter;
use Vortos\Deploy\Definition\DeploymentDefinitionValidator;
use Vortos\Deploy\DependencyInjection\Compiler\CollectContainerRegistriesPass;
use Vortos\Deploy\DependencyInjection\Compiler\CollectCredentialProvidersPass;
use Vortos\Deploy\Driver\GitHubOidc\GitHubActionsOidcTokenSource;
use Vortos\Deploy\Driver\ReleaseKey\ReleaseKeyManifestSigner;
use Vortos\Deploy\Driver\ReleaseKey\ReleaseKeyManifestVerifier;
use Vortos\Deploy\Driver\SshCa\HttpSshCertificateAuthority;
use Vortos\Deploy\Driver\SshCa\OidcRegistryTokenExchange;
use Vortos\Deploy\DependencyInjection\Compiler\CollectDeployStateStoresPass;
use Vortos\Deploy\DependencyInjection\Compiler\CollectDeployStrategiesPass;
use Vortos\Deploy\DependencyInjection\Compiler\CollectDeployTargetsPass;
use Vortos\Deploy\DependencyInjection\Compiler\CollectEdgeRoutersPass;
use Vortos\Deploy\Driver\Caddy\CaddyAdminClient;
use Vortos\Deploy\Driver\Caddy\CaddyCapability;
use Vortos\Deploy\Driver\Caddy\CaddyConfigFragment;
use Vortos\Deploy\Driver\Caddy\CaddyEdgeRouter;
use Vortos\Deploy\Driver\Caddy\DrainObserver;
use Vortos\Deploy\Driver\Caddy\MountedConfigWriter;
use Vortos\Deploy\Driver\Http\HttpReadinessGate;
use Vortos\Deploy\Driver\Http\HttpSmokeRunner;
use Vortos\Deploy\Driver\LocalFile\FileDeployStateStore;
use Vortos\Deploy\Driver\Oci\OciRegistry;
use Vortos\Deploy\Driver\SshCompose\SshComposeTarget;
use Vortos\Deploy\Driver\SshCompose\StepExecutor;
use Vortos\Deploy\Execution\CommandRunnerInterface;
use Vortos\Deploy\Execution\ProcessCommandRunner;
use Vortos\Deploy\Gate\ReadinessGateInterface;
use Vortos\Deploy\Gate\SmokeRunnerInterface;
use Vortos\Deploy\Oci\ImageSignerInterface;
use Vortos\Deploy\Oci\NullImageSigner;
use Vortos\Deploy\Plan\DeployPlanner;
use Vortos\Deploy\Plan\DeployPreflightStateBuilder;
use Vortos\Deploy\Plan\PhaseGate;
use Vortos\Deploy\Plan\PlanRenderer;
use Vortos\Deploy\PullAgent\DesiredStateApplier;
use Vortos\Deploy\PullAgent\DesiredStateManifestFactory;
use Vortos\Deploy\PullAgent\ManifestFreshnessGuard;
use Vortos\Deploy\PullAgent\ManifestFreshnessStoreInterface;
use Vortos\Deploy\PullAgent\ManifestPublisherInterface;
use Vortos\Deploy\PullAgent\ManifestSignerInterface;
use Vortos\Deploy\PullAgent\ManifestSourceInterface;
use Vortos\Deploy\PullAgent\ManifestVerifierInterface;
use Vortos\Deploy\PullAgent\PullAgentReconciler;
use Vortos\Deploy\Rollback\RollbackGuard;
use Vortos\Secrets\Provider\SecretsProviderInterface;
use Vortos\Deploy\Contract\ContractReadinessInterface;
use Vortos\Deploy\Contract\ContractReadinessRegistry;
use Vortos\Deploy\Contract\FlagGateReadiness;
use Vortos\Deploy\Contract\ManualReadiness;
use Vortos\Deploy\Contract\SoakWindowReadiness;
use Vortos\Deploy\Console\ReconcileCommand;
use Vortos\Deploy\DependencyInjection\Compiler\CollectContractReadinessPass;
use Vortos\Deploy\DependencyInjection\Compiler\CollectWorkerControllersPass;
use Vortos\Deploy\Driver\Supervisor\SupervisorWorkerController;
use Vortos\Deploy\State\ContractSoakLedgerInterface;
use Vortos\Deploy\State\CurrentReleaseStoreInterface;
use Vortos\Deploy\Worker\WorkerControllerInterface;
use Vortos\Deploy\Worker\WorkerControllerRegistry;
use Vortos\Deploy\Worker\WorkerRolloutCoordinator;
use Vortos\Docker\Worker\WorkerProcessRegistry;
use Vortos\Migration\Schema\FlagGateMetadataReaderInterface;
use Vortos\Migration\Schema\MigrationPhaseReaderInterface;
use Vortos\Migration\Service\MigrationLockSafetyEnforcer;
use Vortos\Release\Migration\AppliedMigrationSetReaderInterface;
use Vortos\Release\ReadModel\ManifestReadModelInterface;
use Vortos\Deploy\Registry\ContainerRegistryInterface;
use Vortos\Deploy\Registry\ContainerRegistryRegistry;
use Vortos\Deploy\State\DeployStateStoreInterface;
use Vortos\Deploy\State\DeployStateStoreRegistry;
use Vortos\Deploy\Strategy\BlueGreenStrategy;
use Vortos\Deploy\Strategy\CanaryStrategy;
use Vortos\Deploy\Strategy\DeployStrategyInterface;
use Vortos\Deploy\Strategy\DeployStrategyRegistry;
use Vortos\Deploy\Strategy\RecreateStrategy;
use Vortos\Deploy\Strategy\RollingStrategy;
use Vortos\Deploy\Target\DeployTargetInterface;
use Vortos\Deploy\Target\DeployTargetRegistry;

final class DeployExtension extends Extension
{
    public function getAlias(): string
    {
        return 'vortos_deploy';
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        // ── Block 22: Canary analyzer registry + StatisticalGuard + CanaryGate ──

        $container->register(CollectCanaryAnalyzersPass::LOCATOR_ID)
            ->addTag('container.service_locator')
            ->setArgument(0, []);

        $container->register(CanaryAnalyzerRegistry::class, CanaryAnalyzerRegistry::class)
            ->setArgument('$drivers', new Reference(CollectCanaryAnalyzersPass::LOCATOR_ID))
            ->setPublic(false);

        $container->registerForAutoconfiguration(CanaryAnalyzerInterface::class)
            ->addTag(CollectCanaryAnalyzersPass::TAG);

        $container->register(NullCanaryAnalyzer::class, NullCanaryAnalyzer::class)
            ->addTag(CollectCanaryAnalyzersPass::TAG)
            ->setPublic(false);

        $container->register(StatisticalGuard::class, StatisticalGuard::class)
            ->setPublic(false);

        $container->register(CanaryGate::class, CanaryGate::class)
            ->setArgument('$analyzer', new Reference(NullCanaryAnalyzer::class))
            ->setArgument('$guard', new Reference(StatisticalGuard::class))
            ->setPublic(false);

        // slo-prometheus analyzer uses CanaryMetricsPort (provided by Observability via alias)
        $container->register(SloPrometheusCanaryAnalyzer::class, SloPrometheusCanaryAnalyzer::class)
            ->setAutowired(true)
            ->addTag(CollectCanaryAnalyzersPass::TAG)
            ->setPublic(false);

        // ── Block 22: Canary doctor check ──

        $container->register(CanaryAnalyzerReadyCheck::class, CanaryAnalyzerReadyCheck::class)
            ->setArgument('$analyzers', new Reference(CanaryAnalyzerRegistry::class))
            ->setArgument('$routers', new Reference(EdgeRouterRegistry::class))
            ->addTag(self::PREFLIGHT_CHECK_TAG)
            ->setPublic(false);

        // ── Block 16: audit trail recorder (always registered; zero sinks is a
        //    valid no-op when Observability isn't installed) ──

        $container->register(DeployAuditRecorder::class, DeployAuditRecorder::class)
            ->setArgument('$sinks', [])
            ->setPublic(false);

        $container->registerForAutoconfiguration(DeployAuditSinkInterface::class)
            ->addTag(CollectDeployAuditSinksPass::TAG);

        // ── Target port locator + registry ──

        $container->register(CollectDeployTargetsPass::LOCATOR_ID)
            ->addTag('container.service_locator')
            ->setArgument(0, []);

        $container->register(DeployTargetRegistry::class, DeployTargetRegistry::class)
            ->setArgument('$drivers', new Reference(CollectDeployTargetsPass::LOCATOR_ID))
            ->setPublic(false);

        $container->registerForAutoconfiguration(DeployTargetInterface::class)
            ->addTag(CollectDeployTargetsPass::TAG);

        // ── Container registry port locator + registry ──

        $container->register(CollectContainerRegistriesPass::LOCATOR_ID)
            ->addTag('container.service_locator')
            ->setArgument(0, []);

        $container->register(ContainerRegistryRegistry::class, ContainerRegistryRegistry::class)
            ->setArgument('$drivers', new Reference(CollectContainerRegistriesPass::LOCATOR_ID))
            ->setPublic(false);

        $container->registerForAutoconfiguration(ContainerRegistryInterface::class)
            ->addTag(CollectContainerRegistriesPass::TAG);

        // ── Credential provider port locator + registry ──

        $container->register(CollectCredentialProvidersPass::LOCATOR_ID)
            ->addTag('container.service_locator')
            ->setArgument(0, []);

        $container->register(CredentialProviderRegistry::class, CredentialProviderRegistry::class)
            ->setArgument('$drivers', new Reference(CollectCredentialProvidersPass::LOCATOR_ID))
            ->setPublic(false);

        $container->registerForAutoconfiguration(CredentialProviderInterface::class)
            ->addTag(CollectCredentialProvidersPass::TAG);

        // ── Strategy registry + built-in strategies ──

        $container->register(DeployStrategyRegistry::class, DeployStrategyRegistry::class)
            ->setPublic(false);

        $container->registerForAutoconfiguration(DeployStrategyInterface::class)
            ->addTag(CollectDeployStrategiesPass::TAG);

        foreach ([BlueGreenStrategy::class, RollingStrategy::class, RecreateStrategy::class, CanaryStrategy::class] as $strategyClass) {
            $container->register($strategyClass, $strategyClass)
                ->addTag(CollectDeployStrategiesPass::TAG)
                ->setPublic(false);
        }

        // ── Block 8: Contract readiness port locator + registry ──

        $container->register(CollectContractReadinessPass::LOCATOR_ID)
            ->addTag('container.service_locator')
            ->setArgument(0, []);

        $container->register(ContractReadinessRegistry::class, ContractReadinessRegistry::class)
            ->setArgument('$drivers', new Reference(CollectContractReadinessPass::LOCATOR_ID))
            ->setPublic(false);

        $container->registerForAutoconfiguration(ContractReadinessInterface::class)
            ->addTag(CollectContractReadinessPass::TAG);

        $container->register(ManualReadiness::class, ManualReadiness::class)
            ->addTag(CollectContractReadinessPass::TAG)
            ->setPublic(false);

        $container->register(SoakWindowReadiness::class, SoakWindowReadiness::class)
            ->setArgument('$ledger', new Reference(ContractSoakLedgerInterface::class))
            ->setArgument('$releaseStore', new Reference(CurrentReleaseStoreInterface::class))
            ->setArgument('$requiredDeployCount', (int) ($_ENV['DEPLOY_SOAK_REQUIRED_DEPLOY_COUNT'] ?? 2))
            ->setArgument('$soakDurationSeconds', (int) ($_ENV['DEPLOY_SOAK_DURATION_SECONDS'] ?? 3600))
            ->addTag(CollectContractReadinessPass::TAG)
            ->setPublic(false);

        $this->registerFlagGateReadiness($container);

        // ── Block 8: Phase gate + rollback guard ──

        $container->register(PhaseGate::class, PhaseGate::class)
            ->setPublic(false);

        // ── Pure planner + renderer ──

        $container->register(DeployPlanner::class, DeployPlanner::class)
            ->setArgument('$strategies', new Reference(DeployStrategyRegistry::class))
            ->setArgument('$phaseGate', new Reference(PhaseGate::class))
            ->setPublic(false);

        $container->register(PlanRenderer::class, PlanRenderer::class)
            ->setPublic(false);

        // ── Definition validator ──

        $container->register(DeploymentDefinitionValidator::class, DeploymentDefinitionValidator::class)
            ->setArgument('$targets', new Reference(DeployTargetRegistry::class))
            ->setArgument('$registries', new Reference(ContainerRegistryRegistry::class))
            ->setArgument('$credentials', new Reference(CredentialProviderRegistry::class))
            ->setArgument('$strategies', new Reference(DeployStrategyRegistry::class))
            ->setPublic(false);

        // ── Block 7: State store port locator + registry ──

        $container->register(CollectDeployStateStoresPass::LOCATOR_ID)
            ->addTag('container.service_locator')
            ->setArgument(0, []);

        $container->register(DeployStateStoreRegistry::class, DeployStateStoreRegistry::class)
            ->setArgument('$drivers', new Reference(CollectDeployStateStoresPass::LOCATOR_ID))
            ->setPublic(false);

        $container->registerForAutoconfiguration(DeployStateStoreInterface::class)
            ->addTag(CollectDeployStateStoresPass::TAG);

        // ── Block 7: Execution seam ──

        $container->register(ProcessCommandRunner::class, ProcessCommandRunner::class)
            ->setPublic(false);

        $container->setAlias(CommandRunnerInterface::class, ProcessCommandRunner::class);

        // ── Block 7: Image signer (no-op default, cosign = Block 24) ──

        $container->register(NullImageSigner::class, NullImageSigner::class)
            ->setPublic(false);

        $container->setAlias(ImageSignerInterface::class, NullImageSigner::class);

        // ── Block 7: Gate + smoke (PSR-18 HTTP drivers) ──

        $container->register(HttpReadinessGate::class, HttpReadinessGate::class)
            ->setArgument('$httpClient', new Reference(ClientInterface::class))
            ->setArgument('$requestFactory', new Reference(RequestFactoryInterface::class))
            ->setPublic(false);

        $container->setAlias(ReadinessGateInterface::class, HttpReadinessGate::class);

        $container->register(HttpSmokeRunner::class, HttpSmokeRunner::class)
            ->setArgument('$httpClient', new Reference(ClientInterface::class))
            ->setArgument('$requestFactory', new Reference(RequestFactoryInterface::class))
            ->setPublic(false);

        $container->setAlias(SmokeRunnerInterface::class, HttpSmokeRunner::class);

        // ── Block 7: Compose project factory ──

        $container->register(ComposeProjectFactory::class, ComposeProjectFactory::class)
            ->setPublic(false);

        // ── Block 7: File state store (zero-dep default) ──

        $container->register(FileDeployStateStore::class, FileDeployStateStore::class)
            ->setArgument('$stateDir', '%kernel.project_dir%/var/deploy-state')
            ->addTag(CollectDeployStateStoresPass::TAG)
            ->setPublic(false);

        // ── Block 7: OCI registry driver ──

        $container->register(OciRegistry::class, OciRegistry::class)
            ->setArgument('$runner', new Reference(CommandRunnerInterface::class))
            ->setArgument('$signer', new Reference(ImageSignerInterface::class))
            ->addTag(CollectContainerRegistriesPass::TAG)
            ->setPublic(false);

        // ── Block 8: Rollback guard ──

        if ($container->has(AppliedMigrationSetReaderInterface::class) && $container->has(ManifestReadModelInterface::class)) {
            $container->register(RollbackGuard::class, RollbackGuard::class)
                ->setArgument('$appliedReader', new Reference(AppliedMigrationSetReaderInterface::class))
                ->setArgument('$manifestReadModel', new Reference(ManifestReadModelInterface::class))
                ->setPublic(false);
        }

        // ── Block 8: Preflight state builder ──

        if ($container->has(AppliedMigrationSetReaderInterface::class) && $container->has(MigrationPhaseReaderInterface::class)) {
            $container->register(DeployPreflightStateBuilder::class, DeployPreflightStateBuilder::class)
                ->setArgument('$appliedReader', new Reference(AppliedMigrationSetReaderInterface::class))
                ->setArgument('$phaseReader', new Reference(MigrationPhaseReaderInterface::class))
                ->setArgument('$contractReadiness', new Reference(ManualReadiness::class))
                ->setArgument('$soakLedger', new Reference(ContractSoakLedgerInterface::class))
                ->setArgument('$releaseStore', new Reference(CurrentReleaseStoreInterface::class))
                ->setPublic(false);
        }

        // ── Block 9: Edge-router port locator + registry ──

        $container->register(CollectEdgeRoutersPass::LOCATOR_ID)
            ->addTag('container.service_locator')
            ->setArgument(0, []);

        $container->register(EdgeRouterRegistry::class, EdgeRouterRegistry::class)
            ->setArgument('$drivers', new Reference(CollectEdgeRoutersPass::LOCATOR_ID))
            ->setPublic(false);

        $container->registerForAutoconfiguration(EdgeRouterInterface::class)
            ->addTag(CollectEdgeRoutersPass::TAG);

        // ── Block 9: Caddy driver ──

        $caddyAdminListen = (string) ($_ENV['CADDY_ADMIN_LISTEN'] ?? 'localhost:2019');

        $container->register(CaddyConfigFragment::class, CaddyConfigFragment::class)
            ->setArgument('$adminListen', $caddyAdminListen)
            ->setPublic(false);

        $container->register(CaddyAdminClient::class, CaddyAdminClient::class)
            ->setArgument('$httpClient', new Reference(ClientInterface::class))
            ->setArgument('$requestFactory', new Reference(RequestFactoryInterface::class))
            ->setArgument('$adminBaseUrl', 'http://' . $caddyAdminListen)
            ->setPublic(false);

        $container->register(MountedConfigWriter::class, MountedConfigWriter::class)
            ->setPublic(false);

        $container->register(DrainObserver::class, DrainObserver::class)
            ->setArgument('$adminClient', new Reference(CaddyAdminClient::class))
            ->setPublic(false);

        $container->register(CaddyEdgeRouter::class, CaddyEdgeRouter::class)
            ->setArgument('$adminClient', new Reference(CaddyAdminClient::class))
            ->setArgument('$configFragment', new Reference(CaddyConfigFragment::class))
            ->setArgument('$configWriter', new Reference(MountedConfigWriter::class))
            ->setArgument('$drainObserver', new Reference(DrainObserver::class))
            ->addTag(CollectEdgeRoutersPass::TAG)
            ->setPublic(false);

        // ── Block 9: Cutover event recorder (no-op default, Block 16 wires real) ──

        $container->register(NullCutoverEventRecorder::class, NullCutoverEventRecorder::class)
            ->setPublic(false);

        $container->setAlias(CutoverEventRecorderInterface::class, NullCutoverEventRecorder::class);

        // ── Block 9: Current release store (alias to file state store) ──

        $container->setAlias(CurrentReleaseStoreInterface::class, FileDeployStateStore::class);
        $container->setAlias(ContractSoakLedgerInterface::class, FileDeployStateStore::class);
        $container->setAlias(ManifestFreshnessStoreInterface::class, FileDeployStateStore::class);

        // ── Block 9: Reconcile rate limiter ──

        $container->setAlias(RateLimitStateStoreInterface::class, FileDeployStateStore::class);

        $container->register(ReconcileRateLimiter::class, ReconcileRateLimiter::class)
            ->setArgument('$stateStore', new Reference(RateLimitStateStoreInterface::class))
            ->setPublic(false);

        // ── Block 9: Cutover coordinator ──

        $container->register(CutoverCoordinator::class, CutoverCoordinator::class)
            ->setArgument('$edgeRouter', new Reference(CaddyEdgeRouter::class))
            ->setArgument('$releaseStore', new Reference(CurrentReleaseStoreInterface::class))
            ->setArgument('$eventRecorder', new Reference(CutoverEventRecorderInterface::class))
            ->setPublic(false);

        // ── Block 9: Edge reconciler ──

        $container->register(EdgeReconciler::class, EdgeReconciler::class)
            ->setArgument('$edgeRouter', new Reference(CaddyEdgeRouter::class))
            ->setArgument('$releaseStore', new Reference(CurrentReleaseStoreInterface::class))
            ->setArgument('$composeFactory', new Reference(ComposeProjectFactory::class))
            ->setArgument('$rateLimiter', new Reference(ReconcileRateLimiter::class))
            ->setArgument('$eventRecorder', new Reference(CutoverEventRecorderInterface::class))
            ->setPublic(false);

        // ── Block 9: Reconcile command ──

        $container->register(ReconcileCommand::class, ReconcileCommand::class)
            ->setArgument('$reconciler', new Reference(EdgeReconciler::class))
            ->addTag('console.command')
            ->setPublic(false);

        // ── Block 10: Worker controller port locator + registry ──

        $container->register(CollectWorkerControllersPass::LOCATOR_ID)
            ->addTag('container.service_locator')
            ->setArgument(0, []);

        $container->register(WorkerControllerRegistry::class, WorkerControllerRegistry::class)
            ->setArgument('$drivers', new Reference(CollectWorkerControllersPass::LOCATOR_ID))
            ->setPublic(false);

        $container->registerForAutoconfiguration(WorkerControllerInterface::class)
            ->addTag(CollectWorkerControllersPass::TAG);

        // ── Block 10: Supervisor worker controller driver ──

        $container->register(SupervisorWorkerController::class, SupervisorWorkerController::class)
            ->setArgument('$localRunner', new Reference(CommandRunnerInterface::class))
            ->addTag(CollectWorkerControllersPass::TAG)
            ->setPublic(false);

        // ── Block 10: Worker rollout coordinator ──

        $container->register(WorkerRolloutCoordinator::class, WorkerRolloutCoordinator::class)
            ->setArgument('$controller', new Reference(SupervisorWorkerController::class))
            ->setPublic(false);

        // ── Block 11: Credential providers — zero standing secrets ──

        $container->register(EphemeralKeyPairFactory::class, EphemeralKeyPairFactory::class)
            ->setPublic(false);

        $container->register(SshKeyCredentialProvider::class, SshKeyCredentialProvider::class)
            ->setArgument('$secrets', new Reference(SecretsProviderInterface::class))
            ->addTag(CollectCredentialProvidersPass::TAG)
            ->setPublic(false);

        $container->register(SshCaOidcCredentialProvider::class, SshCaOidcCredentialProvider::class)
            ->setArgument('$oidcSource', new Reference(OidcTokenSourceInterface::class))
            ->setArgument('$ca', new Reference(SshCertificateAuthorityInterface::class))
            ->setArgument('$keyPairFactory', new Reference(EphemeralKeyPairFactory::class))
            ->addTag(CollectCredentialProvidersPass::TAG)
            ->setPublic(false);

        $container->register(PullAgentCredentialProvider::class, PullAgentCredentialProvider::class)
            ->addTag(CollectCredentialProvidersPass::TAG)
            ->setPublic(false);

        // ── Block 11: OIDC / CA / registry-exchange drivers (concrete, env-specific) ──

        $container->register(GitHubActionsOidcTokenSource::class, GitHubActionsOidcTokenSource::class)
            ->setArgument('$httpClient', new Reference(ClientInterface::class))
            ->setArgument('$requestFactory', new Reference(RequestFactoryInterface::class))
            ->setPublic(false);

        $container->setAlias(OidcTokenSourceInterface::class, GitHubActionsOidcTokenSource::class);

        $container->register(HttpSshCertificateAuthority::class, HttpSshCertificateAuthority::class)
            ->setArgument('$httpClient', new Reference(ClientInterface::class))
            ->setArgument('$requestFactory', new Reference(RequestFactoryInterface::class))
            ->setArgument('$caEndpoint', '%vortos.deploy.ssh_ca_endpoint%')
            ->setPublic(false);

        $container->setAlias(SshCertificateAuthorityInterface::class, HttpSshCertificateAuthority::class);

        $container->register(OidcRegistryTokenExchange::class, OidcRegistryTokenExchange::class)
            ->setArgument('$httpClient', new Reference(ClientInterface::class))
            ->setArgument('$requestFactory', new Reference(RequestFactoryInterface::class))
            ->setArgument('$exchangeEndpoint', '%vortos.deploy.registry_exchange_endpoint%')
            ->setPublic(false);

        $container->setAlias(RegistryTokenExchangeInterface::class, OidcRegistryTokenExchange::class);

        // ── Block 11: Governance — deploy approval gate ──

        $container->register(NullDeployApprovalGate::class, NullDeployApprovalGate::class)
            ->setPublic(false);

        $container->setAlias(DeployApprovalGateInterface::class, NullDeployApprovalGate::class);

        // ── Block 11: Pull-agent manifest signing + verification ──

        $container->register(DesiredStateManifestFactory::class, DesiredStateManifestFactory::class)
            ->setPublic(false);

        // ── Block 11: Pull-agent reconciler ──

        $container->register(ManifestFreshnessGuard::class, ManifestFreshnessGuard::class)
            ->setPublic(false);

        $container->register(DesiredStateApplier::class, DesiredStateApplier::class)
            ->setArgument('$releaseStore', new Reference(CurrentReleaseStoreInterface::class))
            ->setArgument('$composeFactory', new Reference(ComposeProjectFactory::class))
            ->setPublic(false);

        $container->register(PullAgentReconciler::class, PullAgentReconciler::class)
            ->setArgument('$source', new Reference(ManifestSourceInterface::class))
            ->setArgument('$verifier', new Reference(ManifestVerifierInterface::class))
            ->setArgument('$freshnessGuard', new Reference(ManifestFreshnessGuard::class))
            ->setArgument('$freshnessStore', new Reference(ManifestFreshnessStoreInterface::class))
            ->setArgument('$applier', new Reference(DesiredStateApplier::class))
            ->setArgument('$rateLimiter', new Reference(ReconcileRateLimiter::class))
            ->setPublic(false);

        // ── Block 11: Pull-agent reconcile command ──

        $container->register(PullAgentReconcileCommand::class, PullAgentReconcileCommand::class)
            ->setArgument('$reconciler', new Reference(PullAgentReconciler::class))
            ->addTag('console.command')
            ->setPublic(false);

        // ── Block 7+8+9+10: StepExecutor + SshComposeTarget ──

        $stepExecutorDef = $container->register(StepExecutor::class, StepExecutor::class)
            ->setArgument('$stateStore', new Reference(FileDeployStateStore::class))
            ->setArgument('$registry', new Reference(OciRegistry::class))
            ->setArgument('$readinessGate', new Reference(ReadinessGateInterface::class))
            ->setArgument('$smokeRunner', new Reference(SmokeRunnerInterface::class))
            ->setArgument('$composeFactory', new Reference(ComposeProjectFactory::class))
            ->setArgument('$localRunner', new Reference(CommandRunnerInterface::class))
            ->setArgument('$cutoverCoordinator', new Reference(CutoverCoordinator::class))
            ->setArgument('$workerCoordinator', new Reference(WorkerRolloutCoordinator::class))
            ->setArgument('$canaryGate', new Reference(CanaryGate::class))
            ->setPublic(false);

        if ($container->has(WorkerProcessRegistry::class)) {
            $stepExecutorDef->setArgument('$workerRegistry', new Reference(WorkerProcessRegistry::class));
        }

        if ($container->has(MigrationLockSafetyEnforcer::class)) {
            $stepExecutorDef->setArgument('$lockEnforcer', new Reference(MigrationLockSafetyEnforcer::class));
        }
        if ($container->has(MigrationPhaseReaderInterface::class)) {
            $stepExecutorDef->setArgument('$phaseReader', new Reference(MigrationPhaseReaderInterface::class));
        }

        $sshTargetDef = $container->register(SshComposeTarget::class, SshComposeTarget::class)
            ->setArgument('$planner', new Reference(DeployPlanner::class))
            ->setArgument('$executor', new Reference(StepExecutor::class))
            ->setArgument('$registry', new Reference(OciRegistry::class))
            ->setArgument('$stateStore', new Reference(FileDeployStateStore::class))
            ->setArgument('$releaseStore', new Reference(FileDeployStateStore::class))
            ->addTag(CollectDeployTargetsPass::TAG)
            ->setPublic(false);

        if ($container->has(RollbackGuard::class)) {
            $sshTargetDef->setArgument('$rollbackGuard', new Reference(RollbackGuard::class));
        }

        // ── Block 12: Definition resolver (default builder; the app overrides it
        //    with its config/deploy.php-derived builder) ──

        $container->register(DeploymentDefinitionBuilder::class, DeploymentDefinitionBuilder::class)
            ->setPublic(false);

        $container->register(LayeredDefinitionResolver::class, LayeredDefinitionResolver::class)
            ->setArgument('$baseBuilder', new Reference(DeploymentDefinitionBuilder::class))
            ->setPublic(false);

        // ── Block 12: Preflight checks (tagged → collected, extensible: a future
        //    block adds a gate by tagging a PreflightCheckInterface service) ──

        $container->registerForAutoconfiguration(PreflightCheckInterface::class)
            ->addTag(self::PREFLIGHT_CHECK_TAG);

        $container->register(DriverSetCheck::class, DriverSetCheck::class)
            ->setArgument('$targets', new Reference(DeployTargetRegistry::class))
            ->setArgument('$registries', new Reference(ContainerRegistryRegistry::class))
            ->setArgument('$credentials', new Reference(CredentialProviderRegistry::class))
            ->setArgument('$strategies', new Reference(DeployStrategyRegistry::class))
            ->addTag(self::PREFLIGHT_CHECK_TAG)
            ->setPublic(false);

        $container->register(CapabilityDescriptorCheck::class, CapabilityDescriptorCheck::class)
            ->setArgument('$targets', new Reference(DeployTargetRegistry::class))
            ->setArgument('$strategies', new Reference(DeployStrategyRegistry::class))
            ->addTag(self::PREFLIGHT_CHECK_TAG)
            ->setPublic(false);

        $container->register(CredentialCheck::class, CredentialCheck::class)
            ->setArgument('$credentials', new Reference(CredentialProviderRegistry::class))
            ->addTag(self::PREFLIGHT_CHECK_TAG)
            ->setPublic(false);

        $container->register(TargetArchCheck::class, TargetArchCheck::class)
            ->setArgument('$targets', new Reference(DeployTargetRegistry::class))
            ->addTag(self::PREFLIGHT_CHECK_TAG)
            ->setPublic(false);

        $schemaCheckDef = $container->register(SchemaCompatibilityCheck::class, SchemaCompatibilityCheck::class)
            ->setArgument('$phaseGate', new Reference(PhaseGate::class))
            ->addTag(self::PREFLIGHT_CHECK_TAG)
            ->setPublic(false);

        if ($container->has(ManifestReadModelInterface::class)) {
            $schemaCheckDef->setArgument('$manifestReadModel', new Reference(ManifestReadModelInterface::class));
        }

        // ── Block 21: Migration drift as deploy precondition ──

        if (class_exists(\Vortos\Migration\Safety\SchemaDriftAuditorInterface::class)) {
            $container->register(\Vortos\Deploy\Preflight\Check\MigrationDriftCheck::class, \Vortos\Deploy\Preflight\Check\MigrationDriftCheck::class)
                ->setArgument('$auditor', new Reference(\Vortos\Migration\Safety\SchemaDriftAuditorInterface::class))
                ->addTag(self::PREFLIGHT_CHECK_TAG)
                ->setPublic(false);
        }

        // ── Block 12: Fail-closed doctor (collects all tagged checks) ──

        $container->register(DeployDoctor::class, DeployDoctor::class)
            ->setArgument('$checks', new TaggedIteratorArgument(self::PREFLIGHT_CHECK_TAG))
            ->setPublic(false);

        // ── Block 12: Preflight context factory + runners + commands. These need the
        //    state builder + manifest read model, which only exist when the migration
        //    + release stack is wired — guard exactly as the existing extension does. ──

        $hasContextDeps = $container->has(DeployPreflightStateBuilder::class)
            && $container->has(ManifestReadModelInterface::class);

        if ($hasContextDeps) {
            $container->register(PreflightContextFactory::class, PreflightContextFactory::class)
                ->setArgument('$resolver', new Reference(LayeredDefinitionResolver::class))
                ->setArgument('$manifestReadModel', new Reference(ManifestReadModelInterface::class))
                ->setArgument('$stateBuilder', new Reference(DeployPreflightStateBuilder::class))
                ->setArgument('$targets', new Reference(DeployTargetRegistry::class))
                ->setPublic(false);

            $container->register(DoctorCommand::class, DoctorCommand::class)
                ->setArgument('$contextFactory', new Reference(PreflightContextFactory::class))
                ->setArgument('$doctor', new Reference(DeployDoctor::class))
                ->addTag('console.command')
                ->setPublic(false);
        }

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

            $container->register(RollbackCommand::class, RollbackCommand::class)
                ->setArgument('$runner', new Reference(RollbackRunner::class))
                ->addTag('console.command')
                ->setPublic(false);

            $container->register(DeployRunner::class, DeployRunner::class)
                ->setArgument('$contextFactory', new Reference(PreflightContextFactory::class))
                ->setArgument('$targets', new Reference(DeployTargetRegistry::class))
                ->setArgument('$planRenderer', new Reference(PlanRenderer::class))
                ->setArgument('$doctor', new Reference(DeployDoctor::class))
                ->setArgument('$rollbackRunner', new Reference(RollbackRunner::class))
                ->setArgument('$auditRecorder', new Reference(DeployAuditRecorder::class))
                ->setPublic(false);

            $container->register(DeployCommand::class, DeployCommand::class)
                ->setArgument('$runner', new Reference(DeployRunner::class))
                ->addTag('console.command')
                ->setPublic(false);
        }
    }

    private function registerFlagGateReadiness(ContainerBuilder $container): void
    {
        $definition = $container->register(FlagGateReadiness::class, FlagGateReadiness::class)
            ->setArgument('$flagGateReader', new Reference(FlagGateMetadataReaderInterface::class))
            ->setArgument('$metricSource', null)
            ->setArgument('$windowSeconds', (int) ($_ENV['DEPLOY_FLAG_GATE_WINDOW_SECONDS'] ?? 3600))
            ->setArgument('$maxAllowedExposureRate', (float) ($_ENV['DEPLOY_FLAG_GATE_MAX_EXPOSURE_RATE'] ?? 0.0))
            ->addTag(CollectContractReadinessPass::TAG)
            ->setPublic(false);

        // Optional: only wired when vortos-feature-flags is installed (guarded by
        // interface_exists(), the same pattern AnalyticsExtension uses for its FF
        // exposure bridge). Without it, FlagGateReadiness still registers but stays
        // permanently fail-closed (no metric source to prove zero old-code exposure).
        if (interface_exists(\Vortos\FeatureFlags\Guardrail\MetricSource\GuardrailMetricSourceInterface::class)) {
            // IGNORE_ON_INVALID_REFERENCE: degrades to null (fail-closed) if the class is
            // autoloadable but the FeatureFlags extension was never actually registered in
            // this kernel — never a hard boot failure either way.
            $definition->setArgument(
                '$metricSource',
                new Reference(
                    \Vortos\FeatureFlags\Guardrail\MetricSource\GuardrailMetricSourceInterface::class,
                    ContainerInterface::IGNORE_ON_INVALID_REFERENCE,
                ),
            );
        }
    }

    private const PREFLIGHT_CHECK_TAG = 'vortos.deploy.preflight_check';
}
