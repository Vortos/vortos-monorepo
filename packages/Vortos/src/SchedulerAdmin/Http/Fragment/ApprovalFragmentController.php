<?php

declare(strict_types=1);

namespace Vortos\SchedulerAdmin\Http\Fragment;

use Symfony\Component\Routing\Attribute\Route;
use Vortos\Http\Attribute\AsController;
use Vortos\Http\Exception\NotFoundException;
use Vortos\Http\Request;
use Vortos\Http\Response;
use Vortos\Scheduler\Schedule\ScheduleId;
use Vortos\Scheduler\Security\Approval\FourEyesApprovalStoreInterface;
use Vortos\SchedulerAdmin\Rendering\TwigRenderer;

/**
 * HTMX fragment: inline approval badge/status for a specific schedule.
 * Used by the detail page to show current pending approvals without a full reload.
 */
#[AsController]
final class ApprovalFragmentController
{
    public function __construct(
        private readonly TwigRenderer                   $renderer,
        private readonly FourEyesApprovalStoreInterface $approvalStore,
    ) {}

    #[Route('/admin/scheduler/{scheduleId}/approvals', name: 'vortos.admin.scheduler.fragment.approvals', methods: ['GET'])]
    public function list(Request $request, string $scheduleId): Response
    {
        $approvals = $this->approvalStore->findBySchedule(ScheduleId::fromString($scheduleId));

        return $this->renderer->renderFragment('approvals/_schedule_approvals.html.twig', [
            'schedule_id' => $scheduleId,
            'approvals'   => $approvals,
            'prefix'      => '/admin/scheduler',
        ]);
    }

    #[Route('/admin/scheduler/approvals/{approvalId}/status', name: 'vortos.admin.scheduler.fragment.approval_status', methods: ['GET'])]
    public function status(Request $request, string $approvalId): Response
    {
        $approval = $this->approvalStore->findById($approvalId);

        if ($approval === null) {
            throw new NotFoundException("Approval '{$approvalId}' not found.");
        }

        return $this->renderer->renderFragment('approvals/_status_badge.html.twig', [
            'approval' => $approval,
            'prefix'   => '/admin/scheduler',
        ]);
    }
}
