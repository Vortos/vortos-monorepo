<?php

declare(strict_types=1);

namespace Vortos\SchedulerAdmin\DependencyInjection;

use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Vortos\Auth\Identity\CurrentUserProvider;
use Vortos\Auth\Security\TokenFreshnessGuardInterface;
use Vortos\Http\MiddlewareOrder;
use Vortos\Scheduler\Audit\SchedulerAuditRepositoryInterface;
use Vortos\Scheduler\Registry\StaticScheduleRegistry;
use Vortos\Scheduler\Security\Approval\FourEyesApprovalStoreInterface;
use Vortos\Scheduler\Security\FourEyesGate;
use Vortos\Scheduler\Security\FourEyesGateInterface;
use Vortos\Scheduler\Security\SchedulePolicyInterface;
use Vortos\Scheduler\Service\ScheduleService;
use Vortos\Scheduler\Service\ScheduleServiceInterface;
use Vortos\Scheduler\Store\ScheduleRunStoreInterface;
use Vortos\Scheduler\Store\ScheduleStoreInterface;
use Vortos\SchedulerAdmin\AdminConfig;
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
use Vortos\SchedulerAdmin\Rendering\AdminTwigExtension;
use Vortos\SchedulerAdmin\Rendering\TwigRenderer;
use Vortos\SchedulerAdmin\Security\CsrfTokenManager;
use Vortos\SchedulerAdmin\Security\StepUpGuard;

final class SchedulerAdminExtension extends Extension
{
    public function getAlias(): string
    {
        return 'vortos_scheduler_admin';
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        $enabled = $container->hasParameter('scheduler_admin.enabled')
            ? (bool) $container->getParameter('scheduler_admin.enabled')
            : true;

        $prefix = $container->hasParameter('scheduler_admin.prefix')
            ? (string) $container->getParameter('scheduler_admin.prefix')
            : '/admin/scheduler';

        $requiredRole = $container->hasParameter('scheduler_admin.required_role')
            ? (string) $container->getParameter('scheduler_admin.required_role')
            : 'ROLE_SCHEDULER_ADMIN';

        $tokenFreshnessSec = $container->hasParameter('scheduler_admin.token_freshness_sec')
            ? (int) $container->getParameter('scheduler_admin.token_freshness_sec')
            : 900;

        $assetBasePath = $container->hasParameter('scheduler_admin.asset_base_path')
            ? (string) $container->getParameter('scheduler_admin.asset_base_path')
            : '/bundles/scheduler-admin/build';

        $twoFaChallengeUrl = $container->hasParameter('scheduler_admin.two_fa_challenge_url')
            ? (string) $container->getParameter('scheduler_admin.two_fa_challenge_url')
            : '/auth/2fa/challenge';

        $loginUrl = $container->hasParameter('scheduler_admin.login_url')
            ? (string) $container->getParameter('scheduler_admin.login_url')
            : '/login';

        $previewMaxCount = $container->hasParameter('scheduler_admin.preview_max_count')
            ? (int) $container->getParameter('scheduler_admin.preview_max_count')
            : 10;

        $container->register(AdminConfig::class, AdminConfig::class)
            ->setArguments([
                $enabled,
                $prefix,
                $requiredRole,
                $tokenFreshnessSec,
                $twoFaChallengeUrl,
                $loginUrl,
                $assetBasePath,
                $previewMaxCount,
            ])
            ->setShared(true)
            ->setPublic(false);

        if (!$enabled) {
            return;
        }

        // --- Security primitives ---

        $container->register(CsrfTokenManager::class, CsrfTokenManager::class)
            ->setArgument('$requestStack', new Reference(RequestStack::class))
            ->setShared(true)
            ->setPublic(false);

        $container->register(StepUpGuard::class, StepUpGuard::class)
            ->setArgument('$freshnessGuard', new Reference(TokenFreshnessGuardInterface::class, ContainerInterface::NULL_ON_INVALID_REFERENCE))
            ->setArgument('$freshnessWindowSec', $tokenFreshnessSec)
            ->setShared(true)
            ->setPublic(false);

        // --- Middleware stack (outermost → innermost: CSP > Auth > CSRF > StepUp) ---

        $container->register(AdminCspMiddleware::class, AdminCspMiddleware::class)
            ->setArgument('$config', new Reference(AdminConfig::class))
            ->addTag('vortos.http_middleware', ['priority' => MiddlewareOrder::OUTERMOST - 10])
            ->setShared(true)
            ->setPublic(false);

        $container->register(AdminAuthMiddleware::class, AdminAuthMiddleware::class)
            ->setArgument('$currentUser', new Reference(CurrentUserProvider::class))
            ->setArgument('$config', new Reference(AdminConfig::class))
            ->addTag('vortos.http_middleware', ['priority' => MiddlewareOrder::AUTH])
            ->setShared(true)
            ->setPublic(false);

        $container->register(CsrfMiddleware::class, CsrfMiddleware::class)
            ->setArgument('$csrf', new Reference(CsrfTokenManager::class))
            ->setArgument('$config', new Reference(AdminConfig::class))
            ->addTag('vortos.http_middleware', ['priority' => MiddlewareOrder::CSRF])
            ->setShared(true)
            ->setPublic(false);

        $container->register(StepUpMiddleware::class, StepUpMiddleware::class)
            ->setArgument('$currentUser', new Reference(CurrentUserProvider::class))
            ->setArgument('$stepUpGuard', new Reference(StepUpGuard::class))
            ->setArgument('$config', new Reference(AdminConfig::class))
            ->addTag('vortos.http_middleware', ['priority' => MiddlewareOrder::TWO_FACTOR])
            ->setShared(true)
            ->setPublic(false);

        // --- Twig rendering (isolated FilesystemLoader) ---

        $viewDir = dirname(__DIR__) . '/View';

        $container->register('vortos.scheduler_admin.twig_loader', FilesystemLoader::class)
            ->setArguments([[$viewDir]])
            ->setShared(true)
            ->setPublic(false);

        $container->register('vortos.scheduler_admin.twig', Environment::class)
            ->setArguments([new Reference('vortos.scheduler_admin.twig_loader'), [
                'autoescape'       => 'html',
                'strict_variables' => true,
                'cache'            => false,
            ]])
            ->setShared(true)
            ->setPublic(false);

        $container->register(AdminTwigExtension::class, AdminTwigExtension::class)
            ->setArgument('$csrf', new Reference(CsrfTokenManager::class))
            ->setArgument('$requestStack', new Reference(RequestStack::class))
            ->setArgument('$assetBasePath', $assetBasePath)
            ->setShared(true)
            ->setPublic(false);

        $container->register(TwigRenderer::class, TwigRenderer::class)
            ->setArgument('$twig', new Reference('vortos.scheduler_admin.twig'))
            ->setShared(true)
            ->setPublic(false);

        // --- Page controllers ---

        $container->register(SchedulerDashboardController::class, SchedulerDashboardController::class)
            ->setArgument('$renderer', new Reference(TwigRenderer::class))
            ->setArgument('$scheduleStore', new Reference(ScheduleStoreInterface::class))
            ->setArgument('$staticRegistry', new Reference(StaticScheduleRegistry::class))
            ->setArgument('$runStore', new Reference(ScheduleRunStoreInterface::class))
            ->addTag('vortos.api.controller')
            ->setPublic(true);

        $container->register(ScheduleDetailController::class, ScheduleDetailController::class)
            ->setArgument('$renderer', new Reference(TwigRenderer::class))
            ->setArgument('$service', new Reference(ScheduleService::class))
            ->setArgument('$auditRepo', new Reference(SchedulerAuditRepositoryInterface::class))
            ->setArgument('$policy', new Reference(SchedulePolicyInterface::class))
            ->setArgument('$currentUser', new Reference(CurrentUserProvider::class))
            ->addTag('vortos.api.controller')
            ->setPublic(true);

        $container->register(ScheduleCreateController::class, ScheduleCreateController::class)
            ->setArgument('$renderer', new Reference(TwigRenderer::class))
            ->setArgument('$service', new Reference(ScheduleService::class))
            ->setArgument('$currentUser', new Reference(CurrentUserProvider::class))
            ->addTag('vortos.api.controller')
            ->setPublic(true);

        $container->register(ScheduleEditController::class, ScheduleEditController::class)
            ->setArgument('$renderer', new Reference(TwigRenderer::class))
            ->setArgument('$service', new Reference(ScheduleService::class))
            ->setArgument('$policy', new Reference(SchedulePolicyInterface::class))
            ->setArgument('$currentUser', new Reference(CurrentUserProvider::class))
            ->addTag('vortos.api.controller')
            ->setPublic(true);

        $container->register(ScheduleDeleteController::class, ScheduleDeleteController::class)
            ->setArgument('$renderer', new Reference(TwigRenderer::class))
            ->setArgument('$service', new Reference(ScheduleService::class))
            ->setArgument('$currentUser', new Reference(CurrentUserProvider::class))
            ->addTag('vortos.api.controller')
            ->setPublic(true);

        $container->register(ApprovalController::class, ApprovalController::class)
            ->setArgument('$renderer', new Reference(TwigRenderer::class))
            ->setArgument('$service', new Reference(ScheduleService::class))
            ->setArgument('$approvalStore', new Reference(FourEyesApprovalStoreInterface::class))
            ->setArgument('$fourEyesGate', new Reference(FourEyesGate::class), ContainerInterface::NULL_ON_INVALID_REFERENCE)
            ->setArgument('$currentUser', new Reference(CurrentUserProvider::class))
            ->addTag('vortos.api.controller')
            ->setPublic(true);

        $container->register(AuditLogController::class, AuditLogController::class)
            ->setArgument('$renderer', new Reference(TwigRenderer::class))
            ->setArgument('$auditRepo', new Reference(SchedulerAuditRepositoryInterface::class))
            ->addTag('vortos.api.controller')
            ->setPublic(true);

        $container->register(ObservabilityController::class, ObservabilityController::class)
            ->setArgument('$renderer', new Reference(TwigRenderer::class))
            ->setArgument('$scheduleStore', new Reference(ScheduleStoreInterface::class))
            ->setArgument('$staticRegistry', new Reference(StaticScheduleRegistry::class))
            ->setArgument('$runStore', new Reference(ScheduleRunStoreInterface::class))
            ->addTag('vortos.api.controller')
            ->setPublic(true);

        // --- Fragment controllers (HTMX partials) ---

        $container->register(ScheduleFragmentController::class, ScheduleFragmentController::class)
            ->setArgument('$renderer', new Reference(TwigRenderer::class))
            ->setArgument('$service', new Reference(ScheduleService::class))
            ->setArgument('$policy', new Reference(SchedulePolicyInterface::class))
            ->setArgument('$currentUser', new Reference(CurrentUserProvider::class))
            ->addTag('vortos.api.controller')
            ->setPublic(true);

        $container->register(TriggerPreviewFragmentController::class, TriggerPreviewFragmentController::class)
            ->setArgument('$renderer', new Reference(TwigRenderer::class))
            ->setArgument('$config', new Reference(AdminConfig::class))
            ->addTag('vortos.api.controller')
            ->setPublic(true);

        $container->register(ApprovalFragmentController::class, ApprovalFragmentController::class)
            ->setArgument('$renderer', new Reference(TwigRenderer::class))
            ->setArgument('$approvalStore', new Reference(FourEyesApprovalStoreInterface::class))
            ->addTag('vortos.api.controller')
            ->setPublic(true);
    }
}
