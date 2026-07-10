<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Vortos\Deploy\Credential\CredentialProviderRegistry;
use Vortos\Deploy\Driver\Docker\ImageReclaimer;
use Vortos\Deploy\Reclaim\Schedule\ImageGcSchedule;
use Vortos\Deploy\Reclaim\Schedule\ReclaimImagesCommand;
use Vortos\Deploy\Reclaim\Schedule\ReclaimImagesHandler;
use Vortos\Scheduler\DependencyInjection\Compiler\StaticSchedulePass;
use Vortos\Deploy\Definition\DeploymentDefinitionValidator;
use Vortos\Deploy\DependencyInjection\Compiler\CollectContainerRegistriesPass;
use Vortos\Deploy\DependencyInjection\Compiler\CollectCredentialProvidersPass;
use Vortos\Deploy\DependencyInjection\Compiler\CollectDeployTargetsPass;
use Vortos\Deploy\Console\PullAgentReconcileCommand;
use Vortos\Deploy\Cutover\RateLimitStateStoreInterface;
use Vortos\Deploy\DependencyInjection\DeployExtension;
use Vortos\Deploy\Driver\LocalFile\FileDeployStateStore;
use Vortos\Deploy\Driver\Mongo\MongoDeployStateStore;
use Vortos\Deploy\Driver\Redis\RedisDeployStateStore;
use Vortos\Deploy\Driver\SshCompose\SshComposeTarget;
use Vortos\Deploy\Driver\SshCompose\StepExecutor;
use Vortos\Deploy\Execution\SshConnectionSettings;
use Vortos\Deploy\Plan\DeployPlanner;
use Vortos\Deploy\PullAgent\ManifestFreshnessStoreInterface;
use Vortos\Deploy\State\ContractSoakLedgerInterface;
use Vortos\Deploy\State\CurrentReleaseStoreInterface;
use Vortos\Deploy\PullAgent\PullAgentReconciler;
use Vortos\Deploy\Plan\PlanRenderer;
use Vortos\Deploy\Registry\ContainerRegistryRegistry;
use Vortos\Deploy\Strategy\BlueGreenStrategy;
use Vortos\Deploy\Strategy\CanaryStrategy;
use Vortos\Deploy\Strategy\DeployStrategyRegistry;
use Vortos\Deploy\Strategy\RecreateStrategy;
use Vortos\Deploy\Strategy\RollingStrategy;
use Vortos\Deploy\Target\DeployTargetRegistry;

final class DeployExtensionTest extends TestCase
{
    public function test_alias(): void
    {
        $ext = new DeployExtension();
        self::assertSame('vortos_deploy', $ext->getAlias());
    }

    public function test_registers_all_core_services(): void
    {
        $container = new ContainerBuilder();
        $ext = new DeployExtension();
        $ext->load([], $container);

        self::assertTrue($container->hasDefinition(DeployTargetRegistry::class));
        self::assertTrue($container->hasDefinition(ContainerRegistryRegistry::class));
        self::assertTrue($container->hasDefinition(CredentialProviderRegistry::class));
        self::assertTrue($container->hasDefinition(DeployStrategyRegistry::class));
        self::assertTrue($container->hasDefinition(DeployPlanner::class));
        self::assertTrue($container->hasDefinition(PlanRenderer::class));
        self::assertTrue($container->hasDefinition(DeploymentDefinitionValidator::class));
    }

    public function test_registers_image_reclaimer_and_scheduled_gc(): void
    {
        $container = new ContainerBuilder();
        (new DeployExtension())->load([], $container);

        // Layer 1+2: the reference-counted reclaimer is always wired and injected into the target.
        self::assertTrue($container->hasDefinition(ImageReclaimer::class));
        self::assertInstanceOf(
            Reference::class,
            $container->getDefinition(SshComposeTarget::class)->getArgument('$reclaimer'),
        );

        // Layer 3: the scheduled safety-net wires only when vortos-scheduler + vortos-cqrs are present
        // (both are in the monorepo autoload), mirroring how SchedulerExtension gates its own schedule.
        $scheduled = interface_exists('Vortos\\Cqrs\\Command\\CommandBusInterface')
            && class_exists(StaticSchedulePass::class);

        self::assertSame($scheduled, $container->hasDefinition(ReclaimImagesCommand::class));
        self::assertSame($scheduled, $container->hasDefinition(ReclaimImagesHandler::class));
        self::assertSame($scheduled, $container->hasDefinition(ImageGcSchedule::class));

        if ($scheduled) {
            self::assertTrue(
                $container->getDefinition(ImageGcSchedule::class)->hasTag(StaticSchedulePass::TAG),
                'the image-gc schedule must carry the static-schedule tag so StaticSchedulePass discovers it',
            );
            self::assertTrue(
                $container->getDefinition(ReclaimImagesHandler::class)->hasTag('vortos.command_handler'),
            );
        }
    }

    /** @return list<string> the four control-plane store interfaces that must share one durable store */
    private static function stateStoreInterfaces(): array
    {
        return [
            CurrentReleaseStoreInterface::class,
            ContractSoakLedgerInterface::class,
            ManifestFreshnessStoreInterface::class,
            RateLimitStateStoreInterface::class,
        ];
    }

    private function loadWithStateStore(?string $kind): ContainerBuilder
    {
        $prev = $_ENV['DEPLOY_STATE_STORE'] ?? null;
        if ($kind === null) {
            unset($_ENV['DEPLOY_STATE_STORE']);
        } else {
            $_ENV['DEPLOY_STATE_STORE'] = $kind;
        }

        try {
            $container = new ContainerBuilder();
            (new DeployExtension())->load([], $container);

            return $container;
        } finally {
            if ($prev === null) {
                unset($_ENV['DEPLOY_STATE_STORE']);
            } else {
                $_ENV['DEPLOY_STATE_STORE'] = $prev;
            }
        }
    }

    public function test_deploy_state_store_defaults_to_redis(): void
    {
        $container = $this->loadWithStateStore(null);

        foreach (self::stateStoreInterfaces() as $iface) {
            self::assertSame(RedisDeployStateStore::class, (string) $container->getAlias($iface), $iface);
        }
        // The concrete consumers that took FileDeployStateStore directly now share the durable store.
        self::assertSame(RedisDeployStateStore::class, (string) $container->getDefinition(StepExecutor::class)->getArgument('$stateStore'));
        self::assertSame(RedisDeployStateStore::class, (string) $container->getDefinition(SshComposeTarget::class)->getArgument('$stateStore'));
        self::assertSame(RedisDeployStateStore::class, (string) $container->getDefinition(SshComposeTarget::class)->getArgument('$releaseStore'));
        self::assertTrue($container->hasDefinition(RedisDeployStateStore::class));
    }

    public function test_deploy_state_store_file_opt_out(): void
    {
        $container = $this->loadWithStateStore('file');

        foreach (self::stateStoreInterfaces() as $iface) {
            self::assertSame(FileDeployStateStore::class, (string) $container->getAlias($iface), $iface);
        }
        self::assertSame(FileDeployStateStore::class, (string) $container->getDefinition(StepExecutor::class)->getArgument('$stateStore'));
    }

    public function test_deploy_state_store_mongo_selector(): void
    {
        $container = $this->loadWithStateStore('mongo');

        self::assertTrue($container->hasDefinition(MongoDeployStateStore::class), 'mongo driver registered only when selected');
        foreach (self::stateStoreInterfaces() as $iface) {
            self::assertSame(MongoDeployStateStore::class, (string) $container->getAlias($iface), $iface);
        }
    }

    public function test_mongo_driver_not_registered_unless_selected(): void
    {
        $container = $this->loadWithStateStore('redis');

        self::assertFalse($container->hasDefinition(MongoDeployStateStore::class));
    }

    public function test_registers_locators(): void
    {
        $container = new ContainerBuilder();
        $ext = new DeployExtension();
        $ext->load([], $container);

        self::assertTrue($container->hasDefinition(CollectDeployTargetsPass::LOCATOR_ID));
        self::assertTrue($container->hasDefinition(CollectContainerRegistriesPass::LOCATOR_ID));
        self::assertTrue($container->hasDefinition(CollectCredentialProvidersPass::LOCATOR_ID));
    }

    public function test_registers_all_four_strategies(): void
    {
        $container = new ContainerBuilder();
        $ext = new DeployExtension();
        $ext->load([], $container);

        self::assertTrue($container->hasDefinition(BlueGreenStrategy::class));
        self::assertTrue($container->hasDefinition(RollingStrategy::class));
        self::assertTrue($container->hasDefinition(RecreateStrategy::class));
        self::assertTrue($container->hasDefinition(CanaryStrategy::class));
    }

    public function test_all_services_are_private(): void
    {
        $container = new ContainerBuilder();
        $ext = new DeployExtension();
        $ext->load([], $container);

        $publicServices = [
            DeployTargetRegistry::class,
            ContainerRegistryRegistry::class,
            CredentialProviderRegistry::class,
            DeployStrategyRegistry::class,
            DeployPlanner::class,
            PlanRenderer::class,
            DeploymentDefinitionValidator::class,
        ];

        foreach ($publicServices as $serviceId) {
            self::assertFalse(
                $container->getDefinition($serviceId)->isPublic(),
                $serviceId . ' should be private.',
            );
        }
    }

    public function test_declares_endpoint_parameters_with_empty_defaults(): void
    {
        // D3: these were referenced as %…% but never setParameter'd → boot failure.
        $prevCa = $_ENV['VORTOS_DEPLOY_SSH_CA_ENDPOINT'] ?? null;
        $prevEx = $_ENV['VORTOS_DEPLOY_REGISTRY_EXCHANGE_ENDPOINT'] ?? null;
        unset($_ENV['VORTOS_DEPLOY_SSH_CA_ENDPOINT'], $_ENV['VORTOS_DEPLOY_REGISTRY_EXCHANGE_ENDPOINT']);

        try {
            $container = new ContainerBuilder();
            (new DeployExtension())->load([], $container);

            self::assertTrue($container->hasParameter('vortos.deploy.ssh_ca_endpoint'));
            self::assertTrue($container->hasParameter('vortos.deploy.registry_exchange_endpoint'));
            self::assertSame('', $container->getParameter('vortos.deploy.ssh_ca_endpoint'));
            self::assertSame('', $container->getParameter('vortos.deploy.registry_exchange_endpoint'));
        } finally {
            if ($prevCa !== null) { $_ENV['VORTOS_DEPLOY_SSH_CA_ENDPOINT'] = $prevCa; }
            if ($prevEx !== null) { $_ENV['VORTOS_DEPLOY_REGISTRY_EXCHANGE_ENDPOINT'] = $prevEx; }
        }
    }

    public function test_pull_agent_stack_is_gated_on_delivery_mode(): void
    {
        // D6: default (push) mode must NOT register the reconciler with its unbound ports, but
        // the reconcile command stays visible (fail-loud at runtime).
        $prev = $_ENV['VORTOS_DEPLOY_DELIVERY_MODE'] ?? null;
        unset($_ENV['VORTOS_DEPLOY_DELIVERY_MODE']);

        try {
            $container = new ContainerBuilder();
            (new DeployExtension())->load([], $container);

            self::assertFalse(
                $container->hasDefinition(PullAgentReconciler::class),
                'Pull-agent reconciler must not register in push mode (its manifest source/verifier are unbound).',
            );
            self::assertTrue(
                $container->hasDefinition(PullAgentReconcileCommand::class),
                'Pull-agent reconcile command stays registered and fails loudly when pull mode is off.',
            );
        } finally {
            if ($prev !== null) { $_ENV['VORTOS_DEPLOY_DELIVERY_MODE'] = $prev; }
        }
    }

    public function test_pull_agent_reconciler_registers_in_pull_mode(): void
    {
        $prev = $_ENV['VORTOS_DEPLOY_DELIVERY_MODE'] ?? null;
        $_ENV['VORTOS_DEPLOY_DELIVERY_MODE'] = 'pull';

        try {
            $container = new ContainerBuilder();
            (new DeployExtension())->load([], $container);

            self::assertTrue(
                $container->hasDefinition(PullAgentReconciler::class),
                'Pull-agent reconciler must register when delivery mode is pull.',
            );
        } finally {
            if ($prev !== null) { $_ENV['VORTOS_DEPLOY_DELIVERY_MODE'] = $prev; } else { unset($_ENV['VORTOS_DEPLOY_DELIVERY_MODE']); }
        }
    }

    public function test_empty_user_and_port_coalesce_to_defaults(): void
    {
        // A CI `docker run -e VAR` fed from an unset GitHub var forwards an empty string, not an
        // absent key. Empty user/port must fall back to deploy/22 rather than binding '' / port 0.
        $env = ['VORTOS_DEPLOY_HOST', 'VORTOS_DEPLOY_USER', 'VORTOS_DEPLOY_PORT'];
        $prev = [];
        foreach ($env as $k) { $prev[$k] = $_ENV[$k] ?? null; }

        $_ENV['VORTOS_DEPLOY_HOST'] = 'deploy.example.com';
        $_ENV['VORTOS_DEPLOY_USER'] = '';
        $_ENV['VORTOS_DEPLOY_PORT'] = '';

        try {
            $container = new ContainerBuilder();
            (new DeployExtension())->load([], $container);

            $settings = $container->getDefinition(SshConnectionSettings::class);
            self::assertSame('deploy.example.com', $settings->getArgument('$host'));
            self::assertSame('deploy', $settings->getArgument('$user'));
            self::assertSame(22, $settings->getArgument('$port'));
        } finally {
            foreach ($env as $k) {
                if ($prev[$k] !== null) { $_ENV[$k] = $prev[$k]; } else { unset($_ENV[$k]); }
            }
        }
    }

    public function test_explicit_user_and_port_are_honoured(): void
    {
        $env = ['VORTOS_DEPLOY_HOST', 'VORTOS_DEPLOY_USER', 'VORTOS_DEPLOY_PORT'];
        $prev = [];
        foreach ($env as $k) { $prev[$k] = $_ENV[$k] ?? null; }

        $_ENV['VORTOS_DEPLOY_HOST'] = 'deploy.example.com';
        $_ENV['VORTOS_DEPLOY_USER'] = 'releaser';
        $_ENV['VORTOS_DEPLOY_PORT'] = '2222';

        try {
            $container = new ContainerBuilder();
            (new DeployExtension())->load([], $container);

            $settings = $container->getDefinition(SshConnectionSettings::class);
            self::assertSame('releaser', $settings->getArgument('$user'));
            self::assertSame(2222, $settings->getArgument('$port'));
        } finally {
            foreach ($env as $k) {
                if ($prev[$k] !== null) { $_ENV[$k] = $prev[$k]; } else { unset($_ENV[$k]); }
            }
        }
    }
}
