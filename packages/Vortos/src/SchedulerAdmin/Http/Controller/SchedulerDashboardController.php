<?php

declare(strict_types=1);

namespace Vortos\SchedulerAdmin\Http\Controller;

use Symfony\Component\Routing\Attribute\Route;
use Vortos\Http\Attribute\AsController;
use Vortos\Http\Request;
use Vortos\Http\Response;
use Vortos\Scheduler\Registry\StaticScheduleRegistry;
use Vortos\Scheduler\Store\ScheduleRunStoreInterface;
use Vortos\Scheduler\Store\ScheduleStoreInterface;
use Vortos\SchedulerAdmin\Rendering\TwigRenderer;

#[AsController]
final class SchedulerDashboardController
{
    public function __construct(
        private readonly TwigRenderer             $renderer,
        private readonly ScheduleStoreInterface    $scheduleStore,
        private readonly StaticScheduleRegistry    $staticRegistry,
        private readonly ScheduleRunStoreInterface $runStore,
    ) {}

    #[Route('/admin/scheduler', name: 'vortos.admin.scheduler.dashboard', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $tenantId     = $request->query->get('tenant') ?: null;
        $statusFilter = $request->query->get('status', '');
        $search       = $request->query->get('q', '');
        $page         = max(1, (int) $request->query->get('page', '1'));
        $perPage      = 50;

        $dynamic = iterator_to_array($this->scheduleStore->findAll($tenantId));
        $static  = $this->staticRegistry->all();
        $all     = array_merge($static, $dynamic);

        if ($search !== '') {
            $lower = strtolower($search);
            $all   = array_filter($all, static fn($s) => str_contains(strtolower($s->name), $lower));
        }

        if ($statusFilter !== '') {
            $all = array_filter($all, static fn($s) => $s->status->value === $statusFilter);
        }

        $all        = array_values($all);
        $total      = count($all);
        $totalPages = max(1, (int) ceil($total / $perPage));
        $schedules  = array_slice($all, ($page - 1) * $perPage, $perPage);

        $ctx = [
            'schedules'     => $schedules,
            'search'        => $search,
            'status_filter' => $statusFilter,
            'tenant_id'     => $tenantId,
            'page'          => $page,
            'total_pages'   => $totalPages,
            'total'         => $total,
            'active_nav'    => 'dashboard',
            'prefix'        => '/admin/scheduler',
        ];

        if ($request->headers->get('HX-Request') === 'true') {
            return $this->renderer->renderFragment('scheduler/_schedule_table.html.twig', $ctx);
        }

        return $this->renderer->render('scheduler/dashboard.html.twig', $ctx);
    }
}
