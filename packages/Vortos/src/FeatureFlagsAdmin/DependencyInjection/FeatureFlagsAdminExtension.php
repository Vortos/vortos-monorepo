<?php

declare(strict_types=1);

namespace Vortos\FeatureFlagsAdmin\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Vortos\Auth\Identity\CurrentUserProvider;
use Vortos\FeatureFlags\Application\FlagWriteService;
use Vortos\FeatureFlags\Authz\Management\ManagementAuthzGateInterface;
use Vortos\FeatureFlags\ChangeRequest\ChangeRequestService;
use Vortos\FeatureFlags\ChangeRequest\Storage\ChangeRequestStorageInterface;
use Vortos\FeatureFlags\Delivery\FlagChangeNotifierInterface;
use Vortos\FeatureFlags\Explain\EvaluationExplainer;
use Vortos\FeatureFlags\FlagScopeContext;
use Vortos\FeatureFlags\Guardrail\GuardrailPolicyService;
use Vortos\FeatureFlags\Guardrail\Storage\GuardrailPolicyStorageInterface;
use Vortos\FeatureFlags\Http\Management\Interceptor\ChangeRequestInterceptorInterface;
use Vortos\FeatureFlags\Http\RateLimit\FlagRateLimitService;
use Vortos\FeatureFlags\ProjectContext;
use Vortos\FeatureFlags\ReadModel\FlagAuditLogRepositoryInterface;
use Vortos\FeatureFlags\ReadModel\FlagStateViewRepositoryInterface;
use Vortos\FeatureFlags\Storage\FlagStorageInterface;
use Vortos\FeatureFlags\Storage\ProjectStorageInterface;
use Vortos\FeatureFlags\Storage\SegmentStorageInterface;
use Vortos\FeatureFlags\Validation\FlagValidator;
use Vortos\FeatureFlagsAdmin\AdminConfig;
use Vortos\FeatureFlagsAdmin\Http\Controller\ApprovalsController;
use Vortos\FeatureFlagsAdmin\Http\Controller\DashboardController;
use Vortos\FeatureFlagsAdmin\Http\Controller\EnvCompareController;
use Vortos\FeatureFlagsAdmin\Http\Controller\FlagAdminStreamController;
use Vortos\FeatureFlagsAdmin\Http\Controller\FlagDetailController;
use Vortos\FeatureFlagsAdmin\Http\Controller\HistoryController;
use Vortos\FeatureFlagsAdmin\Http\Controller\InsightsController;
use Vortos\FeatureFlagsAdmin\Http\Controller\KillSwitchController;
use Vortos\FeatureFlagsAdmin\Http\Controller\SegmentController;
use Vortos\FeatureFlagsAdmin\Http\Fragment\FlagFragmentController;
use Vortos\FeatureFlagsAdmin\Http\Fragment\SegmentFragmentController;
use Vortos\FeatureFlagsAdmin\Http\Middleware\AdminAuthMiddleware;
use Vortos\FeatureFlagsAdmin\Http\Middleware\AdminCspMiddleware;
use Vortos\FeatureFlagsAdmin\Http\Middleware\CsrfMiddleware;
use Vortos\FeatureFlagsAdmin\Rendering\AdminTwigExtension;
use Vortos\FeatureFlagsAdmin\Rendering\TwigRenderer;
use Vortos\FeatureFlagsAdmin\Security\CsrfTokenManager;
use Vortos\Http\MiddlewareOrder;

final class FeatureFlagsAdminExtension extends Extension
{
    public function getAlias(): string
    {
        return 'vortos_feature_flags_admin';
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        $enabled = $container->hasParameter('feature_flags_admin.enabled')
            ? (bool) $container->getParameter('feature_flags_admin.enabled')
            : true;

        $prefix = $container->hasParameter('feature_flags_admin.prefix')
            ? (string) $container->getParameter('feature_flags_admin.prefix')
            : '/admin/flags';

        $requiredRole = $container->hasParameter('feature_flags_admin.required_role')
            ? (string) $container->getParameter('feature_flags_admin.required_role')
            : 'ROLE_ADMIN';

        $assetBasePath = $container->hasParameter('feature_flags_admin.asset_base_path')
            ? (string) $container->getParameter('feature_flags_admin.asset_base_path')
            : '/bundles/feature-flags-admin/build';

        $config = new AdminConfig($enabled, $prefix, $requiredRole);

        $container->register(AdminConfig::class, AdminConfig::class)
            ->setArguments([$enabled, $prefix, $requiredRole])
            ->setShared(true)
            ->setPublic(false);

        if (!$enabled) {
            return;
        }

        // --- Security middleware ---

        $container->register(CsrfTokenManager::class, CsrfTokenManager::class)
            ->setArgument('$requestStack', new Reference(RequestStack::class))
            ->setShared(true)
            ->setPublic(false);

        $container->register(AdminAuthMiddleware::class, AdminAuthMiddleware::class)
            ->setArgument('$currentUser', new Reference(CurrentUserProvider::class))
            ->setArgument('$config', new Reference(AdminConfig::class))
            ->addTag('vortos.http_middleware', ['priority' => MiddlewareOrder::AUTH])
            ->setShared(true)
            ->setPublic(false);

        $container->register(AdminCspMiddleware::class, AdminCspMiddleware::class)
            ->setArgument('$config', new Reference(AdminConfig::class))
            ->addTag('vortos.http_middleware', ['priority' => MiddlewareOrder::OUTERMOST - 10])
            ->setShared(true)
            ->setPublic(false);

        $container->register(CsrfMiddleware::class, CsrfMiddleware::class)
            ->setArgument('$csrf', new Reference(CsrfTokenManager::class))
            ->setArgument('$config', new Reference(AdminConfig::class))
            ->addTag('vortos.http_middleware', ['priority' => MiddlewareOrder::CSRF])
            ->setShared(true)
            ->setPublic(false);

        // --- Twig rendering ---

        $viewDir = dirname(__DIR__) . '/View';

        $container->register('vortos.flags_admin.twig_loader', FilesystemLoader::class)
            ->setArguments([[$viewDir]])
            ->setShared(true)
            ->setPublic(false);

        $container->register('vortos.flags_admin.twig', Environment::class)
            ->setArguments([new Reference('vortos.flags_admin.twig_loader'), [
                'autoescape' => 'html',
                'strict_variables' => true,
                'cache' => false,
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
            ->setArgument('$twig', new Reference('vortos.flags_admin.twig'))
            ->setShared(true)
            ->setPublic(false);

        // --- Page controllers ---

        $container->register(DashboardController::class, DashboardController::class)
            ->setArgument('$renderer', new Reference(TwigRenderer::class))
            ->setArgument('$stateView', new Reference(FlagStateViewRepositoryInterface::class))
            ->setArgument('$authz', new Reference(ManagementAuthzGateInterface::class))
            ->setArgument('$currentUser', new Reference(CurrentUserProvider::class))
            ->setArgument('$rateLimit', new Reference(FlagRateLimitService::class))
            ->setArgument('$scopeContext', new Reference(FlagScopeContext::class))
            ->setArgument('$projectContext', new Reference(ProjectContext::class))
            ->addTag('vortos.api.controller')
            ->setPublic(true);

        $container->register(FlagDetailController::class, FlagDetailController::class)
            ->setArgument('$renderer', new Reference(TwigRenderer::class))
            ->setArgument('$storage', new Reference(FlagStorageInterface::class))
            ->setArgument('$stateView', new Reference(FlagStateViewRepositoryInterface::class))
            ->setArgument('$auditLog', new Reference(FlagAuditLogRepositoryInterface::class))
            ->setArgument('$authz', new Reference(ManagementAuthzGateInterface::class))
            ->setArgument('$currentUser', new Reference(CurrentUserProvider::class))
            ->setArgument('$rateLimit', new Reference(FlagRateLimitService::class))
            ->setArgument('$scopeContext', new Reference(FlagScopeContext::class))
            ->setArgument('$projectContext', new Reference(ProjectContext::class))
            ->setArgument('$explainer', new Reference(EvaluationExplainer::class))
            ->setArgument('$guardrailStorage', new Reference(GuardrailPolicyStorageInterface::class, ContainerInterface::NULL_ON_INVALID_REFERENCE))
            ->addTag('vortos.api.controller')
            ->setPublic(true);

        $container->register(HistoryController::class, HistoryController::class)
            ->setArgument('$renderer', new Reference(TwigRenderer::class))
            ->setArgument('$auditLog', new Reference(FlagAuditLogRepositoryInterface::class))
            ->setArgument('$storage', new Reference(FlagStorageInterface::class))
            ->setArgument('$writeService', new Reference(FlagWriteService::class))
            ->setArgument('$authz', new Reference(ManagementAuthzGateInterface::class))
            ->setArgument('$currentUser', new Reference(CurrentUserProvider::class))
            ->setArgument('$rateLimit', new Reference(FlagRateLimitService::class))
            ->setArgument('$changeRequestInterceptor', new Reference(ChangeRequestInterceptorInterface::class))
            ->setArgument('$scopeContext', new Reference(FlagScopeContext::class))
            ->addTag('vortos.api.controller')
            ->setPublic(true);

        $container->register(ApprovalsController::class, ApprovalsController::class)
            ->setArgument('$renderer', new Reference(TwigRenderer::class))
            ->setArgument('$changeRequestService', new Reference(ChangeRequestService::class))
            ->setArgument('$changeRequestStorage', new Reference(ChangeRequestStorageInterface::class))
            ->setArgument('$authz', new Reference(ManagementAuthzGateInterface::class))
            ->setArgument('$currentUser', new Reference(CurrentUserProvider::class))
            ->setArgument('$rateLimit', new Reference(FlagRateLimitService::class))
            ->addTag('vortos.api.controller')
            ->setPublic(true);

        $container->register(SegmentController::class, SegmentController::class)
            ->setArgument('$renderer', new Reference(TwigRenderer::class))
            ->setArgument('$segmentStorage', new Reference(SegmentStorageInterface::class))
            ->setArgument('$authz', new Reference(ManagementAuthzGateInterface::class))
            ->setArgument('$currentUser', new Reference(CurrentUserProvider::class))
            ->setArgument('$rateLimit', new Reference(FlagRateLimitService::class))
            ->addTag('vortos.api.controller')
            ->setPublic(true);

        $container->register(InsightsController::class, InsightsController::class)
            ->setArgument('$renderer', new Reference(TwigRenderer::class))
            ->setArgument('$authz', new Reference(ManagementAuthzGateInterface::class))
            ->setArgument('$currentUser', new Reference(CurrentUserProvider::class))
            ->setArgument('$rateLimit', new Reference(FlagRateLimitService::class))
            ->addTag('vortos.api.controller')
            ->setPublic(true);

        $container->register(KillSwitchController::class, KillSwitchController::class)
            ->setArgument('$renderer', new Reference(TwigRenderer::class))
            ->setArgument('$storage', new Reference(FlagStorageInterface::class))
            ->setArgument('$writeService', new Reference(FlagWriteService::class))
            ->setArgument('$authz', new Reference(ManagementAuthzGateInterface::class))
            ->setArgument('$currentUser', new Reference(CurrentUserProvider::class))
            ->setArgument('$rateLimit', new Reference(FlagRateLimitService::class))
            ->setArgument('$scopeContext', new Reference(FlagScopeContext::class))
            ->setArgument('$guardrailStorage', new Reference(GuardrailPolicyStorageInterface::class, ContainerInterface::NULL_ON_INVALID_REFERENCE))
            ->addTag('vortos.api.controller')
            ->setPublic(true);

        $container->register(EnvCompareController::class, EnvCompareController::class)
            ->setArgument('$renderer', new Reference(TwigRenderer::class))
            ->setArgument('$stateView', new Reference(FlagStateViewRepositoryInterface::class))
            ->setArgument('$authz', new Reference(ManagementAuthzGateInterface::class))
            ->setArgument('$currentUser', new Reference(CurrentUserProvider::class))
            ->setArgument('$rateLimit', new Reference(FlagRateLimitService::class))
            ->addTag('vortos.api.controller')
            ->setPublic(true);

        $container->register(FlagAdminStreamController::class, FlagAdminStreamController::class)
            ->setArgument('$notifier', new Reference(FlagChangeNotifierInterface::class))
            ->setArgument('$scopeContext', new Reference(FlagScopeContext::class))
            ->addTag('vortos.api.controller')
            ->setPublic(true);

        // --- Fragment controllers (HTMX partials) ---

        $container->register(FlagFragmentController::class, FlagFragmentController::class)
            ->setArgument('$renderer', new Reference(TwigRenderer::class))
            ->setArgument('$storage', new Reference(FlagStorageInterface::class))
            ->setArgument('$stateView', new Reference(FlagStateViewRepositoryInterface::class))
            ->setArgument('$writeService', new Reference(FlagWriteService::class))
            ->setArgument('$authz', new Reference(ManagementAuthzGateInterface::class))
            ->setArgument('$currentUser', new Reference(CurrentUserProvider::class))
            ->setArgument('$rateLimit', new Reference(FlagRateLimitService::class))
            ->setArgument('$validator', new Reference(FlagValidator::class))
            ->setArgument('$scopeContext', new Reference(FlagScopeContext::class))
            ->setArgument('$projectContext', new Reference(ProjectContext::class))
            ->setArgument('$changeRequestInterceptor', new Reference(ChangeRequestInterceptorInterface::class))
            ->addTag('vortos.api.controller')
            ->setPublic(true);

        $container->register(SegmentFragmentController::class, SegmentFragmentController::class)
            ->setArgument('$renderer', new Reference(TwigRenderer::class))
            ->setArgument('$segmentStorage', new Reference(SegmentStorageInterface::class))
            ->setArgument('$authz', new Reference(ManagementAuthzGateInterface::class))
            ->setArgument('$currentUser', new Reference(CurrentUserProvider::class))
            ->setArgument('$rateLimit', new Reference(FlagRateLimitService::class))
            ->addTag('vortos.api.controller')
            ->setPublic(true);
    }
}
