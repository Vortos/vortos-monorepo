<?php

declare(strict_types=1);

namespace Vortos\FeatureFlagsAdmin\Http\Controller;

use Symfony\Component\Routing\Attribute\Route;
use Vortos\Auth\Identity\CurrentUserProvider;
use Vortos\FeatureFlags\Authz\Management\ManagementAuthzGateInterface;
use Vortos\FeatureFlags\FlagScopeContext;
use Vortos\FeatureFlags\Http\RateLimit\FlagRateLimitService;
use Vortos\FeatureFlags\ProjectContext;
use Vortos\FeatureFlags\ReadModel\FlagStateViewRepositoryInterface;
use Vortos\FeatureFlagsAdmin\Rendering\TwigRenderer;
use Vortos\Http\Attribute\AsController;
use Vortos\Http\Request;
use Vortos\Http\Response;

#[AsController]
final class DashboardController
{
    public function __construct(
        private readonly TwigRenderer $renderer,
        private readonly FlagStateViewRepositoryInterface $stateView,
        private readonly ManagementAuthzGateInterface $authz,
        private readonly CurrentUserProvider $currentUser,
        private readonly FlagRateLimitService $rateLimit,
        private readonly FlagScopeContext $scopeContext,
        private readonly ProjectContext $projectContext,
    ) {}

    #[Route('/admin/flags', name: 'vortos.admin.flags.dashboard', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->authz->requirePermission('flags.admin.access');
        $this->rateLimit->checkManagement($this->currentUser->get()->id());

        $env = $request->query->get('env', 'production');
        $search = $request->query->get('q', '');
        $kindFilter = $request->query->get('kind', '');
        $statusFilter = $request->query->get('status', '');
        $page = max(1, (int) $request->query->get('page', '1'));
        $perPage = 50;

        $this->scopeContext->withEnvironment($env);

        $allFlags = $this->stateView->all($env, 1000);

        if ($search !== '') {
            $searchLower = strtolower($search);
            $allFlags = array_filter($allFlags, static fn($f) => str_contains(strtolower($f->flagName), $searchLower));
        }

        if ($kindFilter !== '') {
            $allFlags = array_filter($allFlags, static fn($f) => $f->kind === $kindFilter);
        }

        if ($statusFilter === 'enabled') {
            $allFlags = array_filter($allFlags, static fn($f) => $f->enabled && !$f->archived);
        } elseif ($statusFilter === 'disabled') {
            $allFlags = array_filter($allFlags, static fn($f) => !$f->enabled && !$f->archived);
        } elseif ($statusFilter === 'archived') {
            $allFlags = array_filter($allFlags, static fn($f) => $f->archived);
        }

        $allFlags = array_values($allFlags);
        $total = count($allFlags);
        $totalPages = max(1, (int) ceil($total / $perPage));
        $flags = array_slice($allFlags, ($page - 1) * $perPage, $perPage);

        if ($request->headers->get('HX-Request') === 'true') {
            return $this->renderer->renderFragment('flags/_flag_table.html.twig', [
                'flags' => $flags,
                'env' => $env,
                'page' => $page,
                'total_pages' => $totalPages,
                'total' => $total,
                'prefix' => '/admin/flags',
            ]);
        }

        return $this->renderer->render('flags/dashboard.html.twig', [
            'flags' => $flags,
            'env' => $env,
            'search' => $search,
            'kind_filter' => $kindFilter,
            'status_filter' => $statusFilter,
            'page' => $page,
            'total_pages' => $totalPages,
            'total' => $total,
            'environments' => ['production', 'staging', 'development', 'test'],
            'active_nav' => 'dashboard',
            'prefix' => '/admin/flags',
        ]);
    }
}
