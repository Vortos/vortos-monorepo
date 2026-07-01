<?php

declare(strict_types=1);

namespace Vortos\SchedulerAdmin\Http\Controller;

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

#[AsController]
final class ScheduleDeleteController
{
    public function __construct(
        private readonly TwigRenderer        $renderer,
        private readonly ScheduleServiceInterface     $service,
        private readonly CurrentUserProvider $currentUser,
    ) {}

    #[Route('/admin/scheduler/{id}/delete', name: 'vortos.admin.scheduler.delete', methods: ['GET'])]
    public function confirm(Request $request, string $id): Response
    {
        $tenantId = $request->query->get('tenant') ?: null;

        try {
            $schedule = $this->service->loadSchedule(ScheduleId::fromString($id), $tenantId);
        } catch (ScheduleNotFoundException | \InvalidArgumentException) {
            throw new NotFoundException("Schedule '{$id}' not found.");
        }

        return $this->renderer->render('scheduler/delete.html.twig', [
            'schedule'   => $schedule,
            'tenant_id'  => $tenantId,
            'active_nav' => 'schedules',
            'prefix'     => '/admin/scheduler',
        ]);
    }

    #[Route('/admin/scheduler/{id}/delete', name: 'vortos.admin.scheduler.delete.post', methods: ['POST'])]
    public function destroy(Request $request, string $id): Response
    {
        $user     = $this->currentUser->get();
        $tenantId = $request->request->get('tenant') ?: null;
        $reason   = trim((string) $request->request->get('reason', ''));

        try {
            $this->service->delete(ScheduleId::fromString($id), $tenantId, $user, $reason ?: null);
        } catch (ScheduleNotFoundException | \InvalidArgumentException) {
            throw new NotFoundException("Schedule '{$id}' not found.");
        } catch (ScheduleAccessDeniedException $e) {
            throw new ForbiddenException($e->getMessage());
        } catch (\DomainException $e) {
            throw new ForbiddenException($e->getMessage());
        }

        if ($request->headers->get('HX-Request') === 'true') {
            return $this->renderer->renderFragment('scheduler/_deleted_notice.html.twig', [
                'id' => $id,
            ]);
        }

        return new Response('', 302, ['Location' => '/admin/scheduler']);
    }
}
