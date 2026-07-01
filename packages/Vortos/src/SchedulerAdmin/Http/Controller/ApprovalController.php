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
use Vortos\Scheduler\Security\Approval\ApprovalAction;
use Vortos\Scheduler\Security\Approval\ApprovalStatus;
use Vortos\Scheduler\Security\Approval\FourEyesApprovalStoreInterface;
use Vortos\Scheduler\Security\FourEyesGateInterface;
use Vortos\Scheduler\Service\ScheduleServiceInterface;
use Vortos\Scheduler\Store\Exception\ScheduleNotFoundException;
use Vortos\SchedulerAdmin\Rendering\TwigRenderer;

#[AsController]
final class ApprovalController
{
    public function __construct(
        private readonly TwigRenderer                  $renderer,
        private readonly ScheduleServiceInterface               $service,
        private readonly FourEyesApprovalStoreInterface $approvalStore,
        private readonly FourEyesGateInterface          $fourEyesGate,
        private readonly CurrentUserProvider           $currentUser,
    ) {}

    #[Route('/admin/scheduler/approvals', name: 'vortos.admin.scheduler.approvals', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $tenantId = $request->query->get('tenant') ?: null;
        $requests = $this->approvalStore->findAllPending($tenantId);

        if ($request->headers->get('HX-Request') === 'true') {
            return $this->renderer->renderFragment('approvals/_request_list.html.twig', [
                'requests'  => $requests,
                'tenant_id' => $tenantId,
                'prefix'    => '/admin/scheduler',
            ]);
        }

        return $this->renderer->render('approvals/index.html.twig', [
            'requests'   => $requests,
            'tenant_id'  => $tenantId,
            'active_nav' => 'approvals',
            'prefix'     => '/admin/scheduler',
        ]);
    }

    #[Route('/admin/scheduler/approvals/{id}/approve', name: 'vortos.admin.scheduler.approvals.approve', methods: ['POST'])]
    public function approve(Request $request, string $id): Response
    {
        $user   = $this->currentUser->get();
        $reason = trim((string) $request->request->get('reason', ''));

        if (strlen($reason) < 10) {
            throw new ForbiddenException('A reason of at least 10 characters is required.');
        }

        $approval = $this->approvalStore->findById($id);
        if ($approval === null) {
            throw new NotFoundException("Approval request '{$id}' not found.");
        }

        if ($approval->requestedBy === $user->id()) {
            throw new ForbiddenException('Self-approval is not permitted (4-eyes policy).');
        }

        $this->fourEyesGate->approve($id, $user->id());

        if ($request->headers->get('HX-Request') === 'true') {
            return $this->renderer->renderFragment('approvals/_approved_badge.html.twig', ['id' => $id]);
        }

        return new Response('', 302, ['Location' => '/admin/scheduler/approvals']);
    }

    #[Route('/admin/scheduler/approvals/{id}/reject', name: 'vortos.admin.scheduler.approvals.reject', methods: ['POST'])]
    public function reject(Request $request, string $id): Response
    {
        $user   = $this->currentUser->get();
        $reason = trim((string) $request->request->get('reason', ''));

        if (strlen($reason) < 10) {
            throw new ForbiddenException('A reason of at least 10 characters is required.');
        }

        $approval = $this->approvalStore->findById($id);
        if ($approval === null) {
            throw new NotFoundException("Approval request '{$id}' not found.");
        }

        if ($approval->requestedBy === $user->id()) {
            throw new ForbiddenException('Self-rejection is not permitted (4-eyes policy).');
        }

        $this->fourEyesGate->reject($id, $user->id());

        if ($request->headers->get('HX-Request') === 'true') {
            return $this->renderer->renderFragment('approvals/_rejected_badge.html.twig', ['id' => $id]);
        }

        return new Response('', 302, ['Location' => '/admin/scheduler/approvals']);
    }

    #[Route('/admin/scheduler/{scheduleId}/request-approval', name: 'vortos.admin.scheduler.request_approval', methods: ['POST'])]
    public function requestApproval(Request $request, string $scheduleId): Response
    {
        $user     = $this->currentUser->get();
        $tenantId = $request->request->get('tenant') ?: null;
        $action   = ApprovalAction::tryFrom((string) $request->request->get('action', ''));
        $reason   = trim((string) $request->request->get('reason', ''));

        if ($action === null) {
            throw new ForbiddenException('Invalid approval action.');
        }

        try {
            $this->service->requestApproval(
                ScheduleId::fromString($scheduleId),
                $tenantId,
                $action,
                $user,
                $reason ?: null,
            );
        } catch (ScheduleNotFoundException) {
            throw new NotFoundException("Schedule '{$scheduleId}' not found.");
        }

        if ($request->headers->get('HX-Request') === 'true') {
            return $this->renderer->renderFragment('approvals/_pending_badge.html.twig', [
                'schedule_id' => $scheduleId,
                'action'      => $action->value,
            ]);
        }

        return new Response('', 302, ['Location' => '/admin/scheduler/approvals']);
    }
}
