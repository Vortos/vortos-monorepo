<?php

declare(strict_types=1);

namespace Vortos\FeatureFlagsAdmin\Tests\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Vortos\FeatureFlagsAdmin\AdminConfig;
use Vortos\FeatureFlagsAdmin\DependencyInjection\FeatureFlagsAdminExtension;
use Vortos\FeatureFlagsAdmin\Http\Controller\DashboardController;
use Vortos\FeatureFlagsAdmin\Http\Controller\FlagDetailController;
use Vortos\FeatureFlagsAdmin\Http\Controller\HistoryController;
use Vortos\FeatureFlagsAdmin\Http\Controller\ApprovalsController;
use Vortos\FeatureFlagsAdmin\Http\Controller\KillSwitchController;
use Vortos\FeatureFlagsAdmin\Http\Controller\EnvCompareController;
use Vortos\FeatureFlagsAdmin\Http\Controller\InsightsController;
use Vortos\FeatureFlagsAdmin\Http\Controller\SegmentController;
use Vortos\FeatureFlagsAdmin\Http\Fragment\FlagFragmentController;
use Vortos\FeatureFlagsAdmin\Http\Fragment\SegmentFragmentController;
use Vortos\FeatureFlagsAdmin\Http\Middleware\AdminAuthMiddleware;
use Vortos\FeatureFlagsAdmin\Http\Middleware\AdminCspMiddleware;
use Vortos\FeatureFlagsAdmin\Http\Middleware\CsrfMiddleware;
use Vortos\FeatureFlagsAdmin\Rendering\TwigRenderer;
use Vortos\FeatureFlagsAdmin\Security\CsrfTokenManager;

final class FeatureFlagsAdminExtensionTest extends TestCase
{
    public function test_loads_all_services_when_enabled(): void
    {
        $container = new ContainerBuilder();
        $extension = new FeatureFlagsAdminExtension();
        $extension->load([], $container);

        $this->assertTrue($container->has(AdminConfig::class));
        $this->assertTrue($container->has(CsrfTokenManager::class));
        $this->assertTrue($container->has(AdminAuthMiddleware::class));
        $this->assertTrue($container->has(AdminCspMiddleware::class));
        $this->assertTrue($container->has(CsrfMiddleware::class));
        $this->assertTrue($container->has(TwigRenderer::class));
        $this->assertTrue($container->has(DashboardController::class));
        $this->assertTrue($container->has(FlagDetailController::class));
        $this->assertTrue($container->has(HistoryController::class));
        $this->assertTrue($container->has(ApprovalsController::class));
        $this->assertTrue($container->has(KillSwitchController::class));
        $this->assertTrue($container->has(EnvCompareController::class));
        $this->assertTrue($container->has(InsightsController::class));
        $this->assertTrue($container->has(SegmentController::class));
        $this->assertTrue($container->has(FlagFragmentController::class));
        $this->assertTrue($container->has(SegmentFragmentController::class));
    }

    public function test_registers_no_services_when_disabled(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('feature_flags_admin.enabled', false);

        $extension = new FeatureFlagsAdminExtension();
        $extension->load([], $container);

        $this->assertTrue($container->has(AdminConfig::class));
        $this->assertFalse($container->has(DashboardController::class));
        $this->assertFalse($container->has(CsrfTokenManager::class));
        $this->assertFalse($container->has(AdminAuthMiddleware::class));
    }

    public function test_controllers_tagged_as_api_controllers(): void
    {
        $container = new ContainerBuilder();
        $extension = new FeatureFlagsAdminExtension();
        $extension->load([], $container);

        $controllers = [
            DashboardController::class,
            FlagDetailController::class,
            HistoryController::class,
            ApprovalsController::class,
            KillSwitchController::class,
            EnvCompareController::class,
            InsightsController::class,
            SegmentController::class,
            FlagFragmentController::class,
            SegmentFragmentController::class,
        ];

        foreach ($controllers as $controller) {
            $def = $container->getDefinition($controller);
            $this->assertTrue(
                $def->hasTag('vortos.api.controller'),
                "{$controller} must be tagged vortos.api.controller",
            );
        }
    }

    public function test_middleware_tagged_with_correct_priority(): void
    {
        $container = new ContainerBuilder();
        $extension = new FeatureFlagsAdminExtension();
        $extension->load([], $container);

        $authDef = $container->getDefinition(AdminAuthMiddleware::class);
        $this->assertTrue($authDef->hasTag('vortos.http_middleware'));

        $cspDef = $container->getDefinition(AdminCspMiddleware::class);
        $this->assertTrue($cspDef->hasTag('vortos.http_middleware'));

        $csrfDef = $container->getDefinition(CsrfMiddleware::class);
        $this->assertTrue($csrfDef->hasTag('vortos.http_middleware'));
    }

    public function test_custom_prefix_configuration(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('feature_flags_admin.prefix', '/custom/admin');

        $extension = new FeatureFlagsAdminExtension();
        $extension->load([], $container);

        $configDef = $container->getDefinition(AdminConfig::class);
        $this->assertSame('/custom/admin', $configDef->getArguments()[1]);
    }

    public function test_custom_role_configuration(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('feature_flags_admin.required_role', 'ROLE_FLAGS_ADMIN');

        $extension = new FeatureFlagsAdminExtension();
        $extension->load([], $container);

        $configDef = $container->getDefinition(AdminConfig::class);
        $this->assertSame('ROLE_FLAGS_ADMIN', $configDef->getArguments()[2]);
    }

    public function test_extension_alias(): void
    {
        $extension = new FeatureFlagsAdminExtension();
        $this->assertSame('vortos_feature_flags_admin', $extension->getAlias());
    }
}
