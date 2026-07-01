<?php

declare(strict_types=1);

namespace Vortos\SchedulerAdmin\Http\Fragment;

use Symfony\Component\Routing\Attribute\Route;
use Vortos\Auth\Identity\CurrentUserProvider;
use Vortos\Http\Attribute\AsController;
use Vortos\Http\Exception\ForbiddenException;
use Vortos\Http\Exception\NotFoundException;
use Vortos\Http\Request;
use Vortos\Http\Response;
use Vortos\Scheduler\Schedule\ScheduleId;
use Vortos\Scheduler\Security\Exception\ScheduleAccessDeniedException;
use Vortos\Scheduler\Security\SchedulePolicyInterface;
use Vortos\Scheduler\Service\ScheduleServiceInterface;
use Vortos\Scheduler\Store\Exception\ScheduleNotFoundException;
use Vortos\SchedulerAdmin\Rendering\TwigRenderer;

#[AsController]
final class ScheduleFragmentController
{
    public function __construct(
        private readonly TwigRenderer          $renderer,
        private readonly ScheduleServiceInterface       $service,
        private readonly SchedulePolicyInterface $policy,
        private readonly CurrentUserProvider   $currentUser,
    ) {}

    #[Route('/admin/scheduler/{id}/pause', name: 'vortos.admin.scheduler.fragment.pause', methods: ['POST'])]
    public function pause(Request $request, string $id): Response
    {
        $user     = $this->currentUser->get();
        $tenantId = $request->request->get('tenant') ?: null;
        $reason   = trim((string) $request->request->get('reason', ''));

        try {
            $schedule = $this->service->pause(ScheduleId::fromString($id), $tenantId, $user, $reason ?: null);
        } catch (ScheduleNotFoundException | \InvalidArgumentException) {
            throw new NotFoundException("Schedule '{$id}' not found.");
        } catch (ScheduleAccessDeniedException $e) {
            throw new ForbiddenException($e->getMessage());
        }

        return $this->renderer->renderFragment('scheduler/_status_badge.html.twig', [
            'schedule' => $schedule,
        ]);
    }

    #[Route('/admin/scheduler/{id}/resume', name: 'vortos.admin.scheduler.fragment.resume', methods: ['POST'])]
    public function resume(Request $request, string $id): Response
    {
        $user     = $this->currentUser->get();
        $tenantId = $request->request->get('tenant') ?: null;

        try {
            $schedule = $this->service->resume(ScheduleId::fromString($id), $tenantId, $user);
        } catch (ScheduleNotFoundException | \InvalidArgumentException) {
            throw new NotFoundException("Schedule '{$id}' not found.");
        } catch (ScheduleAccessDeniedException $e) {
            throw new ForbiddenException($e->getMessage());
        }

        return $this->renderer->renderFragment('scheduler/_status_badge.html.twig', [
            'schedule' => $schedule,
        ]);
    }

    #[Route('/admin/scheduler/{id}/run-now', name: 'vortos.admin.scheduler.fragment.run_now', methods: ['POST'])]
    public function runNow(Request $request, string $id): Response
    {
        $user     = $this->currentUser->get();
        $tenantId = $request->request->get('tenant') ?: null;
        $reason   = trim((string) $request->request->get('reason', ''));

        try {
            $result = $this->service->runNow(ScheduleId::fromString($id), $tenantId, $user, $reason ?: null);
        } catch (ScheduleNotFoundException | \InvalidArgumentException) {
            throw new NotFoundException("Schedule '{$id}' not found.");
        } catch (ScheduleAccessDeniedException $e) {
            throw new ForbiddenException($e->getMessage());
        }

        return $this->renderer->renderFragment('scheduler/_run_result.html.twig', [
            'result'      => $result,
            'schedule_id' => $id,
        ]);
    }
}
