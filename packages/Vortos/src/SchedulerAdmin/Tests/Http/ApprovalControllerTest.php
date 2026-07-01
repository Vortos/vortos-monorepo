<?php

declare(strict_types=1);

namespace Vortos\SchedulerAdmin\Tests\Http;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Vortos\Auth\Identity\CurrentUserProvider;
use Vortos\Auth\Identity\UserIdentity;
use Vortos\Cache\Adapter\ArrayAdapter;
use Vortos\Http\Exception\ForbiddenException;
use Vortos\Http\Exception\NotFoundException;
use Vortos\Http\Request;
use Vortos\Http\Response;
use Vortos\Scheduler\Schedule\ScheduleId;
use Vortos\Scheduler\Security\Approval\ApprovalAction;
use Vortos\Scheduler\Security\Approval\ApprovalRequest;
use Vortos\Scheduler\Security\Approval\ApprovalStatus;
use Vortos\Scheduler\Security\Approval\FourEyesApprovalStoreInterface;
use Vortos\Scheduler\Security\FourEyesGateInterface;
use Vortos\Scheduler\Service\ScheduleServiceInterface;
use Vortos\SchedulerAdmin\Http\Controller\ApprovalController;
use Vortos\SchedulerAdmin\Rendering\TwigRenderer;

final class ApprovalControllerTest extends TestCase
{
    private TwigRenderer&MockObject                    $renderer;
    private ScheduleServiceInterface&MockObject                 $service;
    private FourEyesApprovalStoreInterface&MockObject  $approvalStore;
    private FourEyesGateInterface&MockObject           $fourEyesGate;
    private CurrentUserProvider                        $currentUser;
    private ApprovalController                         $controller;

    protected function setUp(): void
    {
        $this->renderer      = $this->createMock(TwigRenderer::class);
        $this->service       = $this->createMock(ScheduleServiceInterface::class);
        $this->approvalStore = $this->createMock(FourEyesApprovalStoreInterface::class);
        $this->fourEyesGate  = $this->createMock(FourEyesGateInterface::class);

        $adapter = new ArrayAdapter();
        $adapter->set('auth:identity', new UserIdentity('approver-1', ['ROLE_SCHEDULER_ADMIN']));
        $this->currentUser = new CurrentUserProvider($adapter);

        $this->controller = new ApprovalController(
            renderer:      $this->renderer,
            service:       $this->service,
            approvalStore: $this->approvalStore,
            fourEyesGate:  $this->fourEyesGate,
            currentUser:   $this->currentUser,
        );
    }

    public function test_index_renders_pending_approvals(): void
    {
        $this->approvalStore->method('findAllPending')->willReturn([]);
        $this->renderer->expects($this->once())
            ->method('render')
            ->with('approvals/index.html.twig', $this->anything())
            ->willReturn(new Response('<html>'));

        $request  = Request::create('/admin/scheduler/approvals', 'GET');
        $response = $this->controller->index($request);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_index_htmx_renders_fragment(): void
    {
        $this->approvalStore->method('findAllPending')->willReturn([]);
        $this->renderer->expects($this->once())
            ->method('renderFragment')
            ->willReturn(new Response('<div>'));

        $request = Request::create('/admin/scheduler/approvals', 'GET');
        $request->headers->set('HX-Request', 'true');

        $this->controller->index($request);
    }

    public function test_approve_not_found_throws_404(): void
    {
        $this->approvalStore->method('findById')->willReturn(null);

        $request = Request::create('/admin/scheduler/approvals/nonexistent/approve', 'POST', [
            'reason' => 'Approved after thorough review.',
        ]);

        $this->expectException(NotFoundException::class);
        $this->controller->approve($request, 'nonexistent');
    }

    public function test_approve_short_reason_throws_403(): void
    {
        $request = Request::create('/admin/scheduler/approvals/some-id/approve', 'POST', [
            'reason' => 'Too short',
        ]);

        $this->expectException(ForbiddenException::class);
        $this->controller->approve($request, 'some-id');
    }

    public function test_approve_self_approval_throws_403(): void
    {
        $approval = $this->buildApproval(requestedBy: 'approver-1');
        $this->approvalStore->method('findById')->willReturn($approval);

        $request = Request::create('/admin/scheduler/approvals/id-1/approve', 'POST', [
            'reason' => 'Approving this request after review.',
        ]);

        $this->expectException(ForbiddenException::class);
        $this->expectExceptionMessageMatches('/self-approval/i');
        $this->controller->approve($request, 'id-1');
    }

    public function test_approve_valid_request_calls_gate(): void
    {
        $approval = $this->buildApproval(requestedBy: 'other-user');
        $this->approvalStore->method('findById')->willReturn($approval);
        $this->fourEyesGate->expects($this->once())->method('approve')
            ->with('id-1', 'approver-1')
            ->willReturn($approval->withResolution(
                \Vortos\Scheduler\Security\Approval\ApprovalStatus::Approved, 'approver-1', new \DateTimeImmutable(),
            ));
        $this->renderer->method('renderFragment')->willReturn(new Response('<span>'));

        $request = Request::create('/admin/scheduler/approvals/id-1/approve', 'POST', [
            'reason' => 'Approved after thorough review.',
        ]);
        $request->headers->set('HX-Request', 'true');

        $this->controller->approve($request, 'id-1');
    }

    public function test_reject_self_rejection_throws_403(): void
    {
        $approval = $this->buildApproval(requestedBy: 'approver-1');
        $this->approvalStore->method('findById')->willReturn($approval);

        $request = Request::create('/admin/scheduler/approvals/id-1/reject', 'POST', [
            'reason' => 'Rejecting this request because of policy.',
        ]);

        $this->expectException(ForbiddenException::class);
        $this->controller->reject($request, 'id-1');
    }

    public function test_reject_redirects_on_full_page(): void
    {
        $approval = $this->buildApproval(requestedBy: 'other-user');
        $this->approvalStore->method('findById')->willReturn($approval);
        $this->fourEyesGate->method('reject')->willReturn($approval->withResolution(
            ApprovalStatus::Rejected, 'approver-1', new \DateTimeImmutable(),
        ));

        $request = Request::create('/admin/scheduler/approvals/id-1/reject', 'POST', [
            'reason' => 'Rejecting due to security policy.',
        ]);

        $response = $this->controller->reject($request, 'id-1');

        $this->assertSame(302, $response->getStatusCode());
    }

    private function buildApproval(string $requestedBy): ApprovalRequest
    {
        $now = new \DateTimeImmutable();

        return new ApprovalRequest(
            id:          'id-1',
            scheduleId:  ScheduleId::generate(),
            action:      ApprovalAction::RunNow,
            status:      ApprovalStatus::Pending,
            requestedBy: $requestedBy,
            requestedAt: $now,
            expiresAt:   $now->modify('+1 day'),
            reason:      null,
            resolvedBy:  null,
            resolvedAt:  null,
        );
    }
}
