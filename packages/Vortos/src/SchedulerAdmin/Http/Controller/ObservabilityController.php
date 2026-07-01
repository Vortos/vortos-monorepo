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
final class ObservabilityController
{
    public function __construct(
        private readonly TwigRenderer             $renderer,
        private readonly ScheduleStoreInterface    $scheduleStore,
        private readonly StaticScheduleRegistry    $staticRegistry,
        private readonly ScheduleRunStoreInterface $runStore,
    ) {}

    #[Route('/admin/scheduler/observability', name: 'vortos.admin.scheduler.observability', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $tenantId = $request->query->get('tenant') ?: null;

        $dynamic   = iterator_to_array($this->scheduleStore->findAll($tenantId));
        $static    = $this->staticRegistry->all();
        $schedules = array_merge($static, $dynamic);

        $scheduleIds = array_map(static fn($s) => $s->id, $schedules);

        $lastDispatches = $scheduleIds !== []
            ? $this->runStore->findLastDispatchTimes($scheduleIds, $tenantId)
            : [];

        return $this->renderer->render('observability/index.html.twig', [
            'schedules'      => $schedules,
            'last_dispatches' => $lastDispatches,
            'tenant_id'      => $tenantId,
            'active_nav'     => 'observability',
            'prefix'         => '/admin/scheduler',
        ]);
    }
}
