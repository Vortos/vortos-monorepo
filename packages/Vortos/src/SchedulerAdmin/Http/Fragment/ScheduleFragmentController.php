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
use Vortos\Scheduler\Service\ScheduleServiceInterface;
use Vortos\Scheduler\Store\Exception\ScheduleNotFoundException;
use Vortos\SchedulerAdmin\Rendering\TwigRenderer;

/**
 * The HTMX action fragments. Every one of these MUTATES, so authorization is not optional here.
 *
 * It is enforced one layer down, by ScheduleService, which calls assertCanPause/assertCanRunNow
 * before touching anything and throws ScheduleAccessDeniedException — translated to a 403 below.
 * This controller also used to take a SchedulePolicyInterface it never called, which read like a
 * gate that had been forgotten. It was not: it was a second copy of a check the service already
 * makes unconditionally. Injecting it here invited exactly the wrong repair — adding a duplicate
 * check in the controller, so the two could later disagree about who may do what. The soft-check
 * (canPause etc.) belongs in the controllers that RENDER buttons, which is where it lives.
 */
#[AsController]
final class ScheduleFragmentController
{
    public function __construct(
        private readonly TwigRenderer             $renderer,
        private readonly ScheduleServiceInterface $service,
        private readonly CurrentUserProvider      $currentUser,
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
