<?php

declare(strict_types=1);

namespace Vortos\SchedulerAdmin\Http\Controller;

use Symfony\Component\Routing\Attribute\Route;
use Vortos\Auth\Identity\CurrentUserProvider;
use Vortos\Http\Attribute\AsController;
use Vortos\Http\Exception\NotFoundException;
use Vortos\Http\Request;
use Vortos\Http\Response;
use Vortos\Scheduler\Audit\SchedulerAuditRepositoryInterface;
use Vortos\Scheduler\Schedule\ScheduleId;
use Vortos\Scheduler\Security\SchedulePolicyInterface;
use Vortos\Scheduler\Service\ScheduleServiceInterface;
use Vortos\Scheduler\Store\Exception\ScheduleNotFoundException;
use Vortos\SchedulerAdmin\Rendering\TwigRenderer;

#[AsController]
final class ScheduleDetailController
{
    public function __construct(
        private readonly TwigRenderer                        $renderer,
        private readonly ScheduleServiceInterface                     $service,
        private readonly SchedulerAuditRepositoryInterface   $auditRepo,
        private readonly SchedulePolicyInterface             $policy,
        private readonly CurrentUserProvider                 $currentUser,
    ) {}

    #[Route('/admin/scheduler/{id}', name: 'vortos.admin.scheduler.detail', methods: ['GET'])]
    public function show(Request $request, string $id): Response
    {
        $user     = $this->currentUser->get();
        $tenantId = $request->query->get('tenant') ?: null;

        try {
            $schedule = $this->service->loadSchedule(ScheduleId::fromString($id), $tenantId);
        } catch (ScheduleNotFoundException) {
            throw new NotFoundException("Schedule '{$id}' not found.");
        }

        $auditEntries = $this->auditRepo->findBySchedule($id, $tenantId, 100);

        return $this->renderer->render('scheduler/detail.html.twig', [
            'schedule'     => $schedule,
            'audit'        => $auditEntries,
            'tenant_id'    => $tenantId,
            'active_nav'   => 'schedules',
            'prefix'       => '/admin/scheduler',
            'can_edit'     => $this->policy->canUpdate($user, $schedule),
            'can_delete'   => $this->policy->canDelete($user, $schedule),
            'can_pause'    => $this->policy->canPause($user, $schedule),
            'can_run_now'  => $this->policy->canRunNow($user, $schedule),
        ]);
    }
}
