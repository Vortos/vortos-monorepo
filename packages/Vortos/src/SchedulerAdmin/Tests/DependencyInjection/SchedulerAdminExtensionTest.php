<?php

declare(strict_types=1);

namespace Vortos\SchedulerAdmin\Tests\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Vortos\Http\MiddlewareOrder;
use Vortos\SchedulerAdmin\AdminConfig;
use Vortos\SchedulerAdmin\DependencyInjection\SchedulerAdminExtension;
use Vortos\SchedulerAdmin\Http\Controller\ApprovalController;
use Vortos\SchedulerAdmin\Http\Controller\AuditLogController;
use Vortos\SchedulerAdmin\Http\Controller\ObservabilityController;
use Vortos\SchedulerAdmin\Http\Controller\ScheduleCreateController;
use Vortos\SchedulerAdmin\Http\Controller\ScheduleDeleteController;
use Vortos\SchedulerAdmin\Http\Controller\ScheduleDetailController;
use Vortos\SchedulerAdmin\Http\Controller\ScheduleEditController;
use Vortos\SchedulerAdmin\Http\Controller\SchedulerDashboardController;
use Vortos\SchedulerAdmin\Http\Fragment\ApprovalFragmentController;
use Vortos\SchedulerAdmin\Http\Fragment\ScheduleFragmentController;
use Vortos\SchedulerAdmin\Http\Fragment\TriggerPreviewFragmentController;
use Vortos\SchedulerAdmin\Http\Middleware\AdminAuthMiddleware;
use Vortos\SchedulerAdmin\Http\Middleware\AdminCspMiddleware;
use Vortos\SchedulerAdmin\Http\Middleware\CsrfMiddleware;
use Vortos\SchedulerAdmin\Http\Middleware\StepUpMiddleware;
use Vortos\SchedulerAdmin\Rendering\TwigRenderer;
use Vortos\SchedulerAdmin\Security\CsrfTokenManager;
use Vortos\SchedulerAdmin\Security\StepUpGuard;

final class SchedulerAdminExtensionTest extends TestCase
{
    public function test_loads_all_services_when_enabled(): void
    {
        $container = new ContainerBuilder();
        $extension = new SchedulerAdminExtension();
        $extension->load([], $container);

        $this->assertTrue($container->has(AdminConfig::class));
        $this->assertTrue($container->has(CsrfTokenManager::class));
        $this->assertTrue($container->has(StepUpGuard::class));
        $this->assertTrue($container->has(AdminAuthMiddleware::class));
        $this->assertTrue($container->has(AdminCspMiddleware::class));
        $this->assertTrue($container->has(CsrfMiddleware::class));
        $this->assertTrue($container->has(StepUpMiddleware::class));
        $this->assertTrue($container->has(TwigRenderer::class));
        $this->assertTrue($container->has(SchedulerDashboardController::class));
        $this->assertTrue($container->has(ScheduleDetailController::class));
        $this->assertTrue($container->has(ScheduleCreateController::class));
        $this->assertTrue($container->has(ScheduleEditController::class));
        $this->assertTrue($container->has(ScheduleDeleteController::class));
        $this->assertTrue($container->has(ApprovalController::class));
        $this->assertTrue($container->has(AuditLogController::class));
        $this->assertTrue($container->has(ObservabilityController::class));
        $this->assertTrue($container->has(ScheduleFragmentController::class));
        $this->assertTrue($container->has(TriggerPreviewFragmentController::class));
        $this->assertTrue($container->has(ApprovalFragmentController::class));
    }

    public function test_registers_no_services_when_disabled(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('scheduler_admin.enabled', false);

        $extension = new SchedulerAdminExtension();
        $extension->load([], $container);

        $this->assertTrue($container->has(AdminConfig::class));
        $this->assertFalse($container->has(SchedulerDashboardController::class));
        $this->assertFalse($container->has(CsrfTokenManager::class));
        $this->assertFalse($container->has(AdminAuthMiddleware::class));
    }

    public function test_all_controllers_tagged_as_api_controllers(): void
    {
        $container = new ContainerBuilder();
        $extension = new SchedulerAdminExtension();
        $extension->load([], $container);

        $controllers = [
            SchedulerDashboardController::class,
            ScheduleDetailController::class,
            ScheduleCreateController::class,
            ScheduleEditController::class,
            ScheduleDeleteController::class,
            ApprovalController::class,
            AuditLogController::class,
            ObservabilityController::class,
            ScheduleFragmentController::class,
            TriggerPreviewFragmentController::class,
            ApprovalFragmentController::class,
        ];

        foreach ($controllers as $controller) {
            $def = $container->getDefinition($controller);
            $this->assertTrue(
                $def->hasTag('vortos.api.controller'),
                "{$controller} must be tagged vortos.api.controller",
            );
        }
    }

    public function test_middleware_tagged_with_correct_priorities(): void
    {
        $container = new ContainerBuilder();
        $extension = new SchedulerAdminExtension();
        $extension->load([], $container);

        $authDef = $container->getDefinition(AdminAuthMiddleware::class);
        $this->assertTrue($authDef->hasTag('vortos.http_middleware'));
        $tags = $authDef->getTag('vortos.http_middleware');
        $this->assertSame(MiddlewareOrder::AUTH, $tags[0]['priority']);

        $cspDef = $container->getDefinition(AdminCspMiddleware::class);
        $this->assertTrue($cspDef->hasTag('vortos.http_middleware'));

        $csrfDef = $container->getDefinition(CsrfMiddleware::class);
        $this->assertTrue($csrfDef->hasTag('vortos.http_middleware'));
        $csrfTags = $csrfDef->getTag('vortos.http_middleware');
        $this->assertSame(MiddlewareOrder::CSRF, $csrfTags[0]['priority']);

        $stepUpDef = $container->getDefinition(StepUpMiddleware::class);
        $this->assertTrue($stepUpDef->hasTag('vortos.http_middleware'));
        $stepUpTags = $stepUpDef->getTag('vortos.http_middleware');
        $this->assertSame(MiddlewareOrder::TWO_FACTOR, $stepUpTags[0]['priority']);
    }

    public function test_custom_prefix_configuration(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('scheduler_admin.prefix', '/custom/scheduler');

        $extension = new SchedulerAdminExtension();
        $extension->load([], $container);

        $configDef = $container->getDefinition(AdminConfig::class);
        $this->assertSame('/custom/scheduler', $configDef->getArguments()[1]);
    }

    public function test_custom_role_configuration(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('scheduler_admin.required_role', 'ROLE_SUPER_ADMIN');

        $extension = new SchedulerAdminExtension();
        $extension->load([], $container);

        $configDef = $container->getDefinition(AdminConfig::class);
        $this->assertSame('ROLE_SUPER_ADMIN', $configDef->getArguments()[2]);
    }

    public function test_extension_alias(): void
    {
        $extension = new SchedulerAdminExtension();
        $this->assertSame('vortos_scheduler_admin', $extension->getAlias());
    }

    public function test_isolated_twig_environment_registered(): void
    {
        $container = new ContainerBuilder();
        $extension = new SchedulerAdminExtension();
        $extension->load([], $container);

        $this->assertTrue($container->has('vortos.scheduler_admin.twig'));
        $this->assertTrue($container->has('vortos.scheduler_admin.twig_loader'));

        $twigDef = $container->getDefinition('vortos.scheduler_admin.twig');
        $opts    = $twigDef->getArgument(1);
        $this->assertSame('html', $opts['autoescape']);
        $this->assertTrue($opts['strict_variables']);
    }
}
