<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Vortos\Deploy\Credential\CredentialProviderRegistry;
use Vortos\Deploy\Definition\DeploymentDefinitionValidator;
use Vortos\Deploy\DependencyInjection\Compiler\CollectContainerRegistriesPass;
use Vortos\Deploy\DependencyInjection\Compiler\CollectCredentialProvidersPass;
use Vortos\Deploy\DependencyInjection\Compiler\CollectDeployTargetsPass;
use Vortos\Deploy\Console\PullAgentReconcileCommand;
use Vortos\Deploy\DependencyInjection\DeployExtension;
use Vortos\Deploy\Execution\SshConnectionSettings;
use Vortos\Deploy\Plan\DeployPlanner;
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
