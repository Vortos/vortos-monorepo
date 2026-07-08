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
use Vortos\Deploy\Console\MaterializeFileSecretsCommand;
use Vortos\Deploy\Console\EdgeHydrateConfigCommand;
use Vortos\Deploy\Console\ProvisionCommand;
use Vortos\Deploy\Console\PullAgentReconcileCommand;
use Vortos\Deploy\Console\RollbackCommand;
use Vortos\Deploy\Delivery\ArtifactDelivery;
use Vortos\Deploy\Provision\FirstDeployProvisioner;
use Vortos\Deploy\Canary\CanaryAnalyzerInterface;
use Vortos\Deploy\Canary\CanaryAnalyzerRegistry;
use Vortos\Deploy\Canary\CanaryGate;
use Vortos\Deploy\Canary\Driver\NullCanaryAnalyzer;
use Vortos\Deploy\Canary\Driver\SloPrometheusCanaryAnalyzer;
use Vortos\Deploy\Canary\StatisticalGuard;
use Vortos\Deploy\DependencyInjection\Compiler\CollectCanaryAnalyzersPass;
use Vortos\Deploy\DependencyInjection\Compiler\CollectDeployAuditSinksPass;
use Vortos\Deploy\Definition\DeploymentDefinitionBuilder;
use Vortos\Deploy\Definition\DeploymentDefinitionBuilderFactory;
use Vortos\Deploy\Definition\LayeredDefinitionResolver;
use Vortos\Deploy\Preflight\Check\CanaryAnalyzerReadyCheck;
use Vortos\Deploy\Preflight\Check\CapabilityDescriptorCheck;
use Vortos\Deploy\Preflight\Check\CredentialCheck;
use Vortos\Deploy\Preflight\Check\DeployStateDurabilityCheck;
use Vortos\Deploy\Preflight\Check\DriverSetCheck;
use Vortos\Deploy\Preflight\Check\EnvFileReadabilityCheck;
use Vortos\Deploy\Preflight\Check\RootlessWorkerCheck;
use Vortos\Deploy\Preflight\Check\PendingMigrationPhaseCheck;
use Vortos\Deploy\Preflight\Check\SchemaCompatibilityCheck;
use Vortos\Deploy\Preflight\Check\FileSecretsCheck;
use Vortos\Deploy\Preflight\Check\TargetArchCheck;
use Vortos\Deploy\Preflight\Check\WorkerTopologyCheck;
use Vortos\Deploy\Preflight\DeployDoctor;
use Vortos\Deploy\Preflight\PreflightCheckInterface;
use Vortos\Deploy\Preflight\PreflightContextFactory;
use Vortos\Deploy\Runner\DeployRunner;
use Vortos\Deploy\Runner\RollbackRunner;
use Vortos\Deploy\Compose\ComposeProjectFactory;
use Vortos\Deploy\Cutover\EdgeConfigGenerator;
use Vortos\Deploy\Cutover\State\EdgeStateStoreInterface;
use Vortos\Deploy\Cutover\State\FileEdgeStateStore;
use Vortos\Deploy\Cutover\State\RedisEdgeStateStore;
use Vortos\Deploy\Runtime\FileSecretDecryptor;
use Vortos\Deploy\Runtime\FileSecretMaterializer;
use Vortos\Deploy\Runtime\RuntimeServiceSpec;
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
use Vortos\Deploy\DependencyInjection\Compiler\CollectRegistryAuthStrategiesPass;
use Vortos\Deploy\Driver\Registry\Auth\DockerHubAuthStrategy;
use Vortos\Deploy\Driver\Registry\Auth\GcpArtifactRegistryAuthStrategy;
use Vortos\Deploy\Driver\Registry\Auth\GhcrAuthStrategy;
use Vortos\Deploy\Driver\Registry\DockerHubRegistry;
use Vortos\Deploy\Driver\Registry\GcpArtifactRegistry;
use Vortos\Deploy\Driver\Registry\GhcrRegistry;
use Vortos\Deploy\Registry\Auth\RegistryAuthStrategyInterface;
use Vortos\Deploy\Registry\Auth\RegistryAuthStrategyRegistry;
use Vortos\Deploy\Registry\ContainerRegistryInterface;
use Vortos\Deploy\Driver\GitHubOidc\GitHubActionsOidcTokenSource;
use Vortos\Deploy\Driver\ReleaseKey\ReleaseKeyManifestSigner;
use Vortos\Deploy\Driver\Oci\OciArtifactManifestSource;
use Vortos\Deploy\Driver\ReleaseKey\ReleaseKeyManifestVerifier;
use Vortos\Deploy\Driver\SshCa\HttpSshCertificateAuthority;
use Vortos\Deploy\Driver\SshCa\OidcRegistryTokenExchange;
use Vortos\Deploy\DependencyInjection\Compiler\CollectDeployStateStoresPass;
use Vortos\Deploy\DependencyInjection\Compiler\CollectDeployStrategiesPass;
use Vortos\Deploy\DependencyInjection\Compiler\CollectDeployTargetsPass;
use Vortos\Deploy\DependencyInjection\Compiler\CollectEdgeRoutersPass;
use Vortos\Deploy\Driver\Caddy\CaddyAdminClient;
use Vortos\Deploy\Driver\Caddy\CaddyCapability;
use Vortos\Deploy\Driver\Caddy\CaddyEdgeRouter;
use Vortos\Deploy\Driver\Caddy\DrainObserver;
use Vortos\Deploy\Driver\Caddy\MountedConfigWriter;
use Vortos\Deploy\Driver\Http\HttpReadinessGate;
use Vortos\Deploy\Driver\Http\HttpSmokeRunner;
use Vortos\Deploy\Driver\LocalFile\FileDeployStateStore;
use Vortos\Deploy\Driver\Mongo\MongoDeployStateStore;
use Vortos\Deploy\Driver\Oci\OciRegistry;
use Vortos\Deploy\Driver\Redis\RedisDeployStateStore;
use Vortos\Deploy\Driver\SshCompose\SshComposeTarget;
use Vortos\Deploy\Driver\SshCompose\StepExecutor;
use Vortos\Deploy\Execution\CommandRunnerInterface;
use Vortos\Deploy\Execution\DeployConnectionContext;
use Vortos\Deploy\Execution\LazySshTransport;
use Vortos\Deploy\Execution\ProcessCommandRunner;
use Vortos\Deploy\Execution\SshConnectionActivator;
use Vortos\Deploy\Execution\SshConnectionSettings;
use Vortos\Deploy\Execution\SshTransportInterface;
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
use Vortos\Secrets\Provider\SecretsProviderRegistry;
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
        // Endpoint parameters consumed by the OIDC/SSH-CA/registry-exchange drivers below.
        // Declared here (empty = "not configured") so the %…% placeholders always resolve;
        // an unset parameter would otherwise fail container compilation.
        $container->setParameter(
            'vortos.deploy.ssh_ca_endpoint',
            (string) ($_ENV['VORTOS_DEPLOY_SSH_CA_ENDPOINT'] ?? ''),
        );
        $container->setParameter(
            'vortos.deploy.registry_exchange_endpoint',
            (string) ($_ENV['VORTOS_DEPLOY_REGISTRY_EXCHANGE_ENDPOINT'] ?? ''),
        );

        // ── Durable deploy-state store selector (GAP-I) ──
        // The deploy runs as a 'docker run --rm' one-shot in the deploy-in-image topology, so a
        // container-local file store (%kernel.project_dir%/var/deploy-state) is destroyed after every
        // run — erasing the current-release record, so blue-green never alternates color and rollback
        // cannot see the live release. Redis is therefore the DEFAULT (symmetric with EDGE_STATE_STORE);
        // DEPLOY_STATE_STORE=file opts back to the zero-dep file driver for a single-node / infra-less
        // box, and =mongo selects the Mongo driver. All FOUR control-plane ports (current-release,
        // contract-soak ledger, pull-agent freshness, reconcile rate-limit) share ONE durable store, so
        // run/release/soak/freshness/rate-limit state can never diverge across the ephemeral one-shot.
        $container->register(RedisDeployStateStore::class, RedisDeployStateStore::class)
            ->setArgument('$redis', new Reference(\Redis::class, ContainerInterface::NULL_ON_INVALID_REFERENCE))
            ->addTag(CollectDeployStateStoresPass::TAG)
            ->setPublic(false);

        $deployStateStoreKind = strtolower((string) ($_ENV['DEPLOY_STATE_STORE'] ?? 'redis'));
        $deployStateStoreId = match ($deployStateStoreKind) {
            'file' => FileDeployStateStore::class,
            'mongo' => MongoDeployStateStore::class,
            default => RedisDeployStateStore::class,
        };

        if ($deployStateStoreId === MongoDeployStateStore::class) {
            // Opt-in: only wire the Mongo driver when it is the selected store — its MongoDB\Collection
            // dependency is app-provided. Lazy + NULL_ON_INVALID_REFERENCE keeps compilation safe when
            // the collection is absent; the store then fails loud at first use (and the durability
            // preflight check flags the misconfiguration before a deploy runs).
            $container->register(MongoDeployStateStore::class, MongoDeployStateStore::class)
                ->setArgument('$collection', new Reference(\MongoDB\Collection::class, ContainerInterface::NULL_ON_INVALID_REFERENCE))
                ->addTag(CollectDeployStateStoresPass::TAG)
                ->setPublic(false);
        }

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

        // ── Registry auth strategy port locator + registry ──

        $container->register(CollectRegistryAuthStrategiesPass::LOCATOR_ID)
            ->addTag('container.service_locator')
            ->setArgument(0, []);

        $container->register(RegistryAuthStrategyRegistry::class, RegistryAuthStrategyRegistry::class)
            ->setArgument('$drivers', new Reference(CollectRegistryAuthStrategiesPass::LOCATOR_ID))
            ->setPublic(false);

        $container->registerForAutoconfiguration(RegistryAuthStrategyInterface::class)
            ->addTag(CollectRegistryAuthStrategiesPass::TAG);

        // ── Registry auth strategy drivers ──

        foreach ([GhcrAuthStrategy::class, DockerHubAuthStrategy::class, GcpArtifactRegistryAuthStrategy::class] as $stratClass) {
            $container->register($stratClass, $stratClass)
                ->addTag(CollectRegistryAuthStrategiesPass::TAG)
                ->setPublic(false);
        }

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

        // ── Block 7: Compose project factory (spec-driven, B16) ──
        // The RuntimeServiceSpec is sourced from config/deploy.php via the definition builder so the
        // cutover compose renders the app's REAL command / env_file / internal port — never a stub.

        $container->register(RuntimeServiceSpec::class, RuntimeServiceSpec::class)
            ->setFactory([new Reference(DeploymentDefinitionBuilder::class), 'getRuntimeServiceSpec'])
            ->setPublic(false);

        $container->register(ComposeProjectFactory::class, ComposeProjectFactory::class)
            ->setArgument('$spec', new Reference(RuntimeServiceSpec::class))
            ->setPublic(false);

        // Watch-list: the edge dial port is sourced from the same RuntimeServiceSpec as the compose
        // expose port, so app-<color>:<port> can never drift from the container's real port.
        $container->register(EdgeConfigGenerator::class, EdgeConfigGenerator::class)
            ->setFactory([EdgeConfigGenerator::class, 'fromSpec'])
            ->setArgument('$spec', new Reference(RuntimeServiceSpec::class))
            ->setPublic(false);

        // ── Block 7: File state store (zero-dep default) ──

        $container->register(FileDeployStateStore::class, FileDeployStateStore::class)
            ->setArgument('$stateDir', '%kernel.project_dir%/var/deploy-state')
            ->addTag(CollectDeployStateStoresPass::TAG)
            ->setPublic(false);

        // ── Block 7: OCI registry drivers ──
        // OciRegistry: zero-auth, for pre-authenticated or anonymous registries.
        $container->register(OciRegistry::class, OciRegistry::class)
            ->setArgument('$runner', new Reference(CommandRunnerInterface::class))
            ->setArgument('$signer', new Reference(ImageSignerInterface::class))
            ->addTag(CollectContainerRegistriesPass::TAG)
            ->setPublic(false);

        // Per-registry drivers — credentials are null by default; apps inject SecretValue
        // parameters (e.g. via a secrets provider) to activate a specific driver.
        foreach ([GhcrRegistry::class, DockerHubRegistry::class, GcpArtifactRegistry::class] as $regClass) {
            $container->register($regClass, $regClass)
                ->setArgument('$runner', new Reference(CommandRunnerInterface::class))
                ->setArgument('$signer', new Reference(ImageSignerInterface::class))
                ->addTag(CollectContainerRegistriesPass::TAG)
                ->setPublic(false);
        }

        // Default runtime registry alias — apps override this to pick their provider.
        $container->setAlias(ContainerRegistryInterface::class, GhcrRegistry::class)
            ->setPublic(false);

        // ── Block 8: Rollback guard + preflight state builder ──
        // These have hard cross-package dependencies (vortos-release read model,
        // vortos-migration readers) and are wired in DeployWiringPass, where has() reliably
        // reflects the merged container. Registering them here (in load()) would race the
        // extension load order and silently skip them. See DeployWiringPass.

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

        // GAP-D: the admin *bind* address (written verbatim into the pushed config's admin.listen, so
        // the edge keeps binding it after a /load) is decoupled from the admin *connect* URL the
        // deploy one-shot dials. In the deploy-in-image topology the edge is a separate container, so
        // the edge binds e.g. ":2019" while the one-shot connects to "http://edge:2019".
        $caddyAdminListen = (string) ($_ENV['CADDY_ADMIN_LISTEN'] ?? 'localhost:2019');
        $caddyAdminUrl = (string) ($_ENV['CADDY_ADMIN_URL'] ?? ('http://' . $caddyAdminListen));

        // GAP-D: the public TLS domain the edge serves. Threaded into the cutover so the pushed Caddy
        // config keeps the host matcher + tls.automation and a /load preserves the domain's cert.
        $caddyDomain = (string) ($_ENV['CADDY_DOMAIN'] ?? '');

        $container->register(CaddyAdminClient::class, CaddyAdminClient::class)
            ->setArgument('$httpClient', new Reference(ClientInterface::class))
            ->setArgument('$requestFactory', new Reference(RequestFactoryInterface::class))
            ->setArgument('$adminBaseUrl', $caddyAdminUrl)
            ->setPublic(false);

        // GAP-D: durable, cross-node edge routing intent. Redis is the default control-plane store
        // (a fleet of stateless edge nodes all agree on the active color); a file driver is available
        // for a single-node / infra-less edge. EDGE_STATE_STORE=file selects the file driver.
        $container->register(RedisEdgeStateStore::class, RedisEdgeStateStore::class)
            ->setArgument('$redis', new Reference(\Redis::class, ContainerInterface::NULL_ON_INVALID_REFERENCE))
            ->setPublic(false);

        $container->register(FileEdgeStateStore::class, FileEdgeStateStore::class)
            ->setArgument('$baseDir', (string) ($_ENV['EDGE_STATE_DIR'] ?? '/opt/vortos/edge'))
            ->setPublic(false);

        $edgeStateStoreId = ((string) ($_ENV['EDGE_STATE_STORE'] ?? 'redis')) === 'file'
            ? FileEdgeStateStore::class
            : RedisEdgeStateStore::class;
        $container->setAlias(EdgeStateStoreInterface::class, $edgeStateStoreId)->setPublic(false);

        $container->register(DrainObserver::class, DrainObserver::class)
            ->setArgument('$adminClient', new Reference(CaddyAdminClient::class))
            ->setPublic(false);

        // Writes the rendered Caddy config to the edge's on-disk boot file (the file Caddy boots from
        // via "caddy run --config"). The path MUST match the host side of the edge compose's /config
        // bind-mount (EDGE_CONFIG_DIR/caddy.json) so the cutover write and the container's boot config
        // are the same file — this is what makes a Docker daemon restart / reboot self-heal to the
        // CURRENT route. Gets an SSH transport in push mode below; injected into CaddyEdgeRouter there.
        $container->register(MountedConfigWriter::class, MountedConfigWriter::class)
            ->setArgument('$mountedPath', (string) ($_ENV['EDGE_CONFIG_PATH'] ?? '/opt/vortos/edge/config/caddy.json'))
            ->setPublic(false);

        $container->register(CaddyEdgeRouter::class, CaddyEdgeRouter::class)
            ->setArgument('$adminClient', new Reference(CaddyAdminClient::class))
            ->setArgument('$configGenerator', new Reference(EdgeConfigGenerator::class))
            ->setArgument('$stateStore', new Reference(EdgeStateStoreInterface::class))
            ->setArgument('$drainObserver', new Reference(DrainObserver::class))
            ->setArgument('$adminListen', $caddyAdminListen)
            ->addTag(CollectEdgeRoutersPass::TAG)
            ->setPublic(false);

        // ── Block 9: Cutover event recorder (no-op default, Block 16 wires real) ──

        $container->register(NullCutoverEventRecorder::class, NullCutoverEventRecorder::class)
            ->setPublic(false);

        $container->setAlias(CutoverEventRecorderInterface::class, NullCutoverEventRecorder::class);

        // ── Block 9: Current release store (durable store selected above, GAP-I) ──
        // All four control-plane ports share the ONE selected durable store so state never diverges.

        $container->setAlias(CurrentReleaseStoreInterface::class, $deployStateStoreId);
        $container->setAlias(ContractSoakLedgerInterface::class, $deployStateStoreId);
        $container->setAlias(ManifestFreshnessStoreInterface::class, $deployStateStoreId);

        // ── Block 9: Reconcile rate limiter ──

        $container->setAlias(RateLimitStateStoreInterface::class, $deployStateStoreId);

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

        // Pull-based delivery is opt-in. The reconciler needs a ManifestSource + verifier,
        // which are driver-specific (OCI artifact source + release-key verifier) and require
        // configuration. Registering the reconciler unconditionally left those ports unbound
        // and broke container compilation for every push / ssh-compose install. Gate the whole
        // pull stack on the delivery mode; bind the OCI/release-key drivers only in pull mode.
        $deliveryMode = strtolower((string) ($_ENV['VORTOS_DEPLOY_DELIVERY_MODE'] ?? 'push'));

        if ($deliveryMode === 'pull') {
            $container->register(OciArtifactManifestSource::class, OciArtifactManifestSource::class)
                ->setArgument('$registryUrl', (string) ($_ENV['VORTOS_DEPLOY_PULL_REGISTRY_URL'] ?? ''))
                ->setArgument('$repository', (string) ($_ENV['VORTOS_DEPLOY_PULL_REPOSITORY'] ?? ''))
                ->setPublic(false);
            $container->setAlias(ManifestSourceInterface::class, OciArtifactManifestSource::class)
                ->setPublic(false);

            $container->register(ReleaseKeyManifestVerifier::class, ReleaseKeyManifestVerifier::class)
                ->setArgument('$publicKey', (string) ($_ENV['VORTOS_DEPLOY_PULL_RELEASE_PUBLIC_KEY'] ?? ''))
                ->setPublic(false);
            $container->setAlias(ManifestVerifierInterface::class, ReleaseKeyManifestVerifier::class)
                ->setPublic(false);

            $container->register(PullAgentReconciler::class, PullAgentReconciler::class)
                ->setArgument('$source', new Reference(ManifestSourceInterface::class))
                ->setArgument('$verifier', new Reference(ManifestVerifierInterface::class))
                ->setArgument('$freshnessGuard', new Reference(ManifestFreshnessGuard::class))
                ->setArgument('$freshnessStore', new Reference(ManifestFreshnessStoreInterface::class))
                ->setArgument('$applier', new Reference(DesiredStateApplier::class))
                ->setArgument('$rateLimiter', new Reference(ReconcileRateLimiter::class))
                ->setPublic(false);
        }

        // ── Block 11: Pull-agent reconcile command (always visible; fail-loud in push mode) ──

        $container->register(PullAgentReconcileCommand::class, PullAgentReconcileCommand::class)
            ->setArgument('$reconciler', new Reference(PullAgentReconciler::class, ContainerInterface::NULL_ON_INVALID_REFERENCE))
            ->addTag('console.command')
            ->setPublic(false);

        // ── Block 7+8+9+10: StepExecutor + SshComposeTarget ──

        $stepExecutorDef = $container->register(StepExecutor::class, StepExecutor::class)
            ->setArgument('$stateStore', new Reference($deployStateStoreId))
            ->setArgument('$registry', new Reference(ContainerRegistryInterface::class))
            ->setArgument('$readinessGate', new Reference(ReadinessGateInterface::class))
            ->setArgument('$smokeRunner', new Reference(SmokeRunnerInterface::class))
            ->setArgument('$composeFactory', new Reference(ComposeProjectFactory::class))
            ->setArgument('$localRunner', new Reference(CommandRunnerInterface::class))
            ->setArgument('$cutoverCoordinator', new Reference(CutoverCoordinator::class))
            ->setArgument('$workerCoordinator', new Reference(WorkerRolloutCoordinator::class))
            ->setArgument('$canaryGate', new Reference(CanaryGate::class))
            ->setArgument('$edgeDomain', $caddyDomain)
            ->setPublic(false);

        // Optional collaborators — injected if present, null otherwise (constructor defaults
        // to null). NULL_ON_INVALID_REFERENCE resolves absence at compile end with no has().
        $stepExecutorDef->setArgument(
            '$workerRegistry',
            new Reference(WorkerProcessRegistry::class, ContainerInterface::NULL_ON_INVALID_REFERENCE),
        );
        $stepExecutorDef->setArgument(
            '$lockEnforcer',
            new Reference(MigrationLockSafetyEnforcer::class, ContainerInterface::NULL_ON_INVALID_REFERENCE),
        );
        $stepExecutorDef->setArgument(
            '$phaseReader',
            new Reference(MigrationPhaseReaderInterface::class, ContainerInterface::NULL_ON_INVALID_REFERENCE),
        );

        $sshTargetDef = $container->register(SshComposeTarget::class, SshComposeTarget::class)
            ->setArgument('$planner', new Reference(DeployPlanner::class))
            ->setArgument('$executor', new Reference(StepExecutor::class))
            ->setArgument('$registry', new Reference(ContainerRegistryInterface::class))
            ->setArgument('$stateStore', new Reference($deployStateStoreId))
            ->setArgument('$releaseStore', new Reference($deployStateStoreId))
            ->addTag(CollectDeployTargetsPass::TAG)
            ->setPublic(false);

        // RollbackGuard is wired in DeployWiringPass when its cross-package deps exist; inject
        // it optionally so SshComposeTarget degrades gracefully when it is absent.
        $sshTargetDef->setArgument(
            '$rollbackGuard',
            new Reference(RollbackGuard::class, ContainerInterface::NULL_ON_INVALID_REFERENCE),
        );

        // ── Push delivery: real SSH transport wiring ──
        // Only when push mode AND an SSH host is configured do we bind a live transport;
        // otherwise the four consumers keep their null $sshTransport (local execution) and
        // nothing changes for local/dev/pull installs. The connection itself is resolved
        // per-deploy by SshConnectionActivator (credential lease → SshConnectionConfig). This
        // runs after StepExecutor/SshComposeTarget are registered so every consumer definition
        // it mutates already exists.
        if ($deliveryMode === 'push' && (string) ($_ENV['VORTOS_DEPLOY_HOST'] ?? '') !== '') {
            $container->register(DeployConnectionContext::class, DeployConnectionContext::class)
                ->setPublic(false);

            $container->register(SshConnectionSettings::class, SshConnectionSettings::class)
                // Empty string is the canonical "unset" a CI pass-through (docker run -e VAR
                // fed from an unset GitHub var) yields, so coalesce it to the defaults rather
                // than binding an empty user or port 0.
                ->setArgument('$host', (string) $_ENV['VORTOS_DEPLOY_HOST'])
                ->setArgument('$user', ((string) ($_ENV['VORTOS_DEPLOY_USER'] ?? '')) ?: 'deploy')
                ->setArgument('$port', (int) (($_ENV['VORTOS_DEPLOY_PORT'] ?? '') ?: 22))
                ->setPublic(false);

            $container->register(LazySshTransport::class, LazySshTransport::class)
                ->setArgument('$runner', new Reference(CommandRunnerInterface::class))
                ->setArgument('$context', new Reference(DeployConnectionContext::class))
                ->setPublic(false);

            $container->setAlias(SshTransportInterface::class, LazySshTransport::class)
                ->setPublic(false);

            $container->register(SshConnectionActivator::class, SshConnectionActivator::class)
                ->setArgument('$credentials', new Reference(CredentialProviderRegistry::class))
                ->setArgument('$context', new Reference(DeployConnectionContext::class))
                ->setArgument('$settings', new Reference(SshConnectionSettings::class))
                ->setPublic(false);

            // Config/secret delivery over the transport (G3) — available whenever push-mode SSH is
            // wired, so an operator or the push-mode deploy path can ship the deploy dir atomically.
            $container->register(ArtifactDelivery::class, ArtifactDelivery::class)
                ->setArgument('$transport', new Reference(SshTransportInterface::class))
                ->setPublic(false);

            foreach ([StepExecutor::class, MountedConfigWriter::class, SupervisorWorkerController::class] as $consumer) {
                $container->getDefinition($consumer)
                    ->setArgument('$sshTransport', new Reference(SshTransportInterface::class));
            }

            // Only in a push-mode deploy (a real remote edge filesystem) does the cutover persist its
            // rendered config to the edge's boot file. Local/dev keeps $bootConfigWriter null and is
            // unchanged. This is the durability half of the daemon-restart fix: state store → new/scaled
            // nodes; boot file → cold restart of the existing node.
            $container->getDefinition(CaddyEdgeRouter::class)
                ->setArgument('$bootConfigWriter', new Reference(MountedConfigWriter::class));

            // Caddy admin API stays bound to the VPS loopback; reach it through an SSH
            // local port-forward so it is never publicly exposed.
            $container->getDefinition(CaddyAdminClient::class)
                ->setArgument('$sshTransport', new Reference(SshTransportInterface::class));
        }

        // ── Block 12: Definition resolver. The base builder is produced by the factory, which
        //    applies the application's config/deploy.php when present (no service override
        //    needed). ──

        $container->register(DeploymentDefinitionBuilderFactory::class, DeploymentDefinitionBuilderFactory::class)
            ->setPublic(false);

        $container->register(DeploymentDefinitionBuilder::class, DeploymentDefinitionBuilder::class)
            ->setFactory([new Reference(DeploymentDefinitionBuilderFactory::class), '__invoke'])
            ->setArguments(['%kernel.project_dir%'])
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

        // B20: fail closed when an external-supervisor worker topology is declared for a
        // deploy-in-image host that has no reachable supervisord.
        $container->register(WorkerTopologyCheck::class, WorkerTopologyCheck::class)
            ->addTag(self::PREFLIGHT_CHECK_TAG)
            ->setPublic(false);

        // GAP-A: fail closed when a runtime env file is not readable by the deploy one-shot uid, so
        // the nested cutover docker compose up cannot fail "permission denied" at cutover.
        $container->register(EnvFileReadabilityCheck::class, EnvFileReadabilityCheck::class)
            ->addTag(self::PREFLIGHT_CHECK_TAG)
            ->setPublic(false);

        // GAP-B: fail closed when the worker's supervisord config is rootful in the single-image
        // (RideColor) model, where the image runs as a non-root user and can't drop privileges.
        $container->register(RootlessWorkerCheck::class, RootlessWorkerCheck::class)
            ->addTag(self::PREFLIGHT_CHECK_TAG)
            ->setPublic(false);

        // GAP-I: fail closed when the deploy-state store is not durable for the deploy topology
        // (a file store in the --rm one-shot, or redis selected without a Redis connection).
        $container->register(DeployStateDurabilityCheck::class, DeployStateDurabilityCheck::class)
            ->setArgument('$stateStoreKind', $deployStateStoreKind)
            ->setArgument('$pushDelivery', $deliveryMode === 'push')
            ->setArgument('$hasRemoteHost', (string) ($_ENV['VORTOS_DEPLOY_HOST'] ?? '') !== '')
            ->setArgument('$redisConfigured', ($_ENV['REDIS_DSN'] ?? $_ENV['REDIS_HOST'] ?? '') !== '')
            ->addTag(self::PREFLIGHT_CHECK_TAG)
            ->setPublic(false);

        // G8: fail closed when a declared file-shaped secret is missing from the store.
        $container->register(FileSecretsCheck::class, FileSecretsCheck::class)
            ->setArgument('$providers', new Reference(SecretsProviderRegistry::class))
            ->addTag(self::PREFLIGHT_CHECK_TAG)
            ->setPublic(false);

        $schemaCheckDef = $container->register(SchemaCompatibilityCheck::class, SchemaCompatibilityCheck::class)
            ->setArgument('$phaseGate', new Reference(PhaseGate::class))
            ->addTag(self::PREFLIGHT_CHECK_TAG)
            ->setPublic(false);

        $schemaCheckDef->setArgument(
            '$manifestReadModel',
            new Reference(ManifestReadModelInterface::class, ContainerInterface::NULL_ON_INVALID_REFERENCE),
        );

        // R7-3: fail-closed doctor gate that flags pending destructive-but-un-annotated migrations
        // before a deploy is attempted. Only registered when the migration phase reader is present.
        if (interface_exists(MigrationPhaseReaderInterface::class)) {
            $container->register(PendingMigrationPhaseCheck::class, PendingMigrationPhaseCheck::class)
                ->setArgument('$phaseReader', new Reference(MigrationPhaseReaderInterface::class))
                ->addTag(self::PREFLIGHT_CHECK_TAG)
                ->setPublic(false);
        }

        // ── Block 21: Migration drift as deploy precondition ──

        if (class_exists(\Vortos\Migration\Safety\SchemaDriftAuditorInterface::class)) {
            $container->register(\Vortos\Deploy\Preflight\Check\MigrationDriftCheck::class, \Vortos\Deploy\Preflight\Check\MigrationDriftCheck::class)
                ->setArgument('$auditor', new Reference(\Vortos\Migration\Safety\SchemaDriftAuditorInterface::class))
                ->addTag(self::PREFLIGHT_CHECK_TAG)
                ->setPublic(false);
        }

        // ── R8-1: un-published module migration stubs as a deploy precondition ──

        if (class_exists(\Vortos\Migration\Service\UnpublishedStubDetector::class)) {
            $container->register(\Vortos\Deploy\Preflight\Check\UnpublishedStubCheck::class, \Vortos\Deploy\Preflight\Check\UnpublishedStubCheck::class)
                ->setArgument('$detector', new Reference(\Vortos\Migration\Service\UnpublishedStubDetector::class))
                ->addTag(self::PREFLIGHT_CHECK_TAG)
                ->setPublic(false);
        }

        // ── STAGE-F-1: backup toolchain as a deploy precondition (only when vortos-backup is present) ──

        if (class_exists(\Vortos\Backup\Doctor\BackupToolchainInspector::class)) {
            $container->register(\Vortos\Backup\Doctor\BackupToolchainInspector::class, \Vortos\Backup\Doctor\BackupToolchainInspector::class)
                ->setPublic(false);
            $container->register(\Vortos\Deploy\Preflight\Check\BackupToolchainCheck::class, \Vortos\Deploy\Preflight\Check\BackupToolchainCheck::class)
                ->setArgument('$inspector', new Reference(\Vortos\Backup\Doctor\BackupToolchainInspector::class))
                ->setArgument('$configuredEngine', $_ENV['VORTOS_BACKUP_ENGINE'] ?? null)
                // R8-2: decouple "an engine is configured" from "the toolchain must live in the deploy
                // image". When the toolchain runs on a dedicated backup role (the lean-color pattern),
                // set VORTOS_BACKUP_TOOLCHAIN_EXTERNAL=true so the deploy image is not the assertion site.
                ->setArgument('$toolchainExternal', self::envFlag($_ENV['VORTOS_BACKUP_TOOLCHAIN_EXTERNAL'] ?? null))
                ->addTag(self::PREFLIGHT_CHECK_TAG)
                ->setPublic(false);
        }

        // ── Block 12: Fail-closed doctor (collects all tagged checks) ──

        $container->register(DeployDoctor::class, DeployDoctor::class)
            ->setArgument('$checks', new TaggedIteratorArgument(self::PREFLIGHT_CHECK_TAG))
            ->setPublic(false);

        // ── Block 12: Deploy console commands (always registered; fail-loud at runtime) ──
        // PreflightContextFactory + the deploy/rollback runners have hard cross-package deps
        // (release read model + migration readers) and are registered in DeployWiringPass only
        // when those deps exist. The commands, however, register UNCONDITIONALLY here with
        // their factory/runner injected as NULL_ON_INVALID_REFERENCE, so they always appear in
        // the console command list. When the stack is absent the injected dependency is null and the
        // command prints actionable remediation and returns FAILURE — never "command not found".

        $container->register(DoctorCommand::class, DoctorCommand::class)
            ->setArgument('$contextFactory', new Reference(PreflightContextFactory::class, ContainerInterface::NULL_ON_INVALID_REFERENCE))
            ->setArgument('$doctor', new Reference(DeployDoctor::class))
            ->addTag('console.command')
            ->setPublic(false);

        $container->register(RollbackCommand::class, RollbackCommand::class)
            ->setArgument('$runner', new Reference(RollbackRunner::class, ContainerInterface::NULL_ON_INVALID_REFERENCE))
            ->addTag('console.command')
            ->setPublic(false);

        $container->register(DeployCommand::class, DeployCommand::class)
            ->setArgument('$runner', new Reference(DeployRunner::class, ContainerInterface::NULL_ON_INVALID_REFERENCE))
            ->addTag('console.command')
            ->setPublic(false);

        // First-deploy provisioning (G4): pure planner + command. No cross-package deps — the plan
        // just orchestrates existing console commands (keys/migrate/preflight), so it registers
        // unconditionally and appears in the deploy-on-target remote script.
        $container->register(FirstDeployProvisioner::class, FirstDeployProvisioner::class)
            ->setPublic(false);

        $container->register(ProvisionCommand::class, ProvisionCommand::class)
            ->setArgument('$provisioner', new Reference(FirstDeployProvisioner::class))
            ->addTag('console.command')
            ->setPublic(false);

        // G8: materialize declared file-shaped secrets to tmpfs for the cutover mounts. Runs in the
        // deploy one-shot (which holds the age identity + store) before the cutover deploy.
        $container->register(FileSecretDecryptor::class, FileSecretDecryptor::class)->setPublic(false);
        $container->register(FileSecretMaterializer::class, FileSecretMaterializer::class)->setPublic(false);

        $container->register(MaterializeFileSecretsCommand::class, MaterializeFileSecretsCommand::class)
            ->setArgument('$resolver', new Reference(LayeredDefinitionResolver::class))
            ->setArgument('$providers', new Reference(SecretsProviderRegistry::class))
            ->setArgument('$decryptor', new Reference(FileSecretDecryptor::class))
            ->setArgument('$materializer', new Reference(FileSecretMaterializer::class))
            ->addTag('console.command')
            ->setPublic(false);

        // GAP-D (D5): edge boot init step — reconstruct the Caddy config from the durable edge state
        // store. Runs from the app image in the edge compose before Caddy starts (Caddy has no PHP).
        $container->register(EdgeHydrateConfigCommand::class, EdgeHydrateConfigCommand::class)
            ->setArgument('$stateStore', new Reference(EdgeStateStoreInterface::class))
            ->setArgument('$generator', new Reference(EdgeConfigGenerator::class))
            ->addTag('console.command')
            ->setPublic(false);
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

    /**
     * Interpret an env var as a boolean flag. Unset/empty/null → false; otherwise the usual truthy
     * strings ("1", "true", "yes", "on") → true.
     */
    private static function envFlag(mixed $value): bool
    {
        if ($value === null || $value === '') {
            return false;
        }

        return filter_var($value, \FILTER_VALIDATE_BOOL);
    }
}
