<?php

declare(strict_types=1);

namespace Vortos\FeatureFlagsAdmin\Http\Controller;

use Symfony\Component\Routing\Attribute\Route;
use Vortos\Auth\Identity\CurrentUserProvider;
use Vortos\FeatureFlags\Authz\Management\ManagementAuthzGateInterface;
use Vortos\FeatureFlags\ChangeRequest\ChangeRequestService;
use Vortos\FeatureFlags\ChangeRequest\ChangeRequestStatus;
use Vortos\FeatureFlags\ChangeRequest\Storage\ChangeRequestStorageInterface;
use Vortos\FeatureFlags\Http\RateLimit\FlagRateLimitService;
use Vortos\FeatureFlagsAdmin\Rendering\TwigRenderer;
use Vortos\Http\Attribute\AsController;
use Vortos\Http\Exception\ForbiddenException;
use Vortos\Http\Exception\NotFoundException;
use Vortos\Http\Request;
use Vortos\Http\Response;

#[AsController]
final class ApprovalsController
{
    public function __construct(
        private readonly TwigRenderer $renderer,
        private readonly ChangeRequestService $changeRequestService,
        private readonly ChangeRequestStorageInterface $changeRequestStorage,
        private readonly ManagementAuthzGateInterface $authz,
        private readonly CurrentUserProvider $currentUser,
        private readonly FlagRateLimitService $rateLimit,
    ) {}

    #[Route('/admin/flags/approvals', name: 'vortos.admin.flags.approvals', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->authz->requirePermission('flags.approve');
        $this->rateLimit->checkManagement($this->currentUser->get()->id());

        $statusFilter = $request->query->get('status', 'pending');
        $flagFilter = $request->query->get('flag', '');

        $status = ChangeRequestStatus::tryFrom($statusFilter);

        $requests = [];
        if ($flagFilter !== '') {
            $requests = $this->changeRequestStorage->findByFlag(
                $flagFilter,
                '',
                '',
                $status,
            );
        } else {
            $pending = $this->changeRequestStorage->findByFlag('', '', '', $status);
            $requests = $pending;
        }

        if ($request->headers->get('HX-Request') === 'true') {
            return $this->renderer->renderFragment('approvals/_request_list.html.twig', [
                'requests' => $requests,
                'prefix' => '/admin/flags',
            ]);
        }

        return $this->renderer->render('approvals/index.html.twig', [
            'requests' => $requests,
            'status_filter' => $statusFilter,
            'flag_filter' => $flagFilter,
            'active_nav' => 'approvals',
            'prefix' => '/admin/flags',
        ]);
    }

    #[Route('/admin/flags/approvals/{id}/approve', name: 'vortos.admin.flags.approvals.approve', methods: ['POST'])]
    public function approve(Request $request, string $id): Response
    {
        $this->authz->requirePermission('flags.approve');
        $this->rateLimit->checkManagement($this->currentUser->get()->id());

        $reason = trim($request->request->get('reason', ''));
        if (strlen($reason) < 10) {
            throw new ForbiddenException('A reason of at least 10 characters is required.');
        }

        $changeRequest = $this->changeRequestStorage->findById($id);
        if ($changeRequest === null) {
            throw new NotFoundException('Change request not found.');
        }

        $actorId = $this->currentUser->get()->id();
        $this->changeRequestService->vote($id, $actorId, true, $reason);

        if ($request->headers->get('HX-Request') === 'true') {
            return $this->renderer->renderFragment('approvals/_approved_badge.html.twig', [
                'id' => $id,
            ]);
        }

        return new Response('', 302, ['Location' => '/admin/flags/approvals']);
    }

    #[Route('/admin/flags/approvals/{id}/reject', name: 'vortos.admin.flags.approvals.reject', methods: ['POST'])]
    public function reject(Request $request, string $id): Response
    {
        $this->authz->requirePermission('flags.approve');
        $this->rateLimit->checkManagement($this->currentUser->get()->id());

        $reason = trim($request->request->get('reason', ''));
        if (strlen($reason) < 10) {
            throw new ForbiddenException('A reason of at least 10 characters is required.');
        }

        $changeRequest = $this->changeRequestStorage->findById($id);
        if ($changeRequest === null) {
            throw new NotFoundException('Change request not found.');
        }

        $actorId = $this->currentUser->get()->id();
        $this->changeRequestService->vote($id, $actorId, false, $reason);

        if ($request->headers->get('HX-Request') === 'true') {
            return $this->renderer->renderFragment('approvals/_rejected_badge.html.twig', [
                'id' => $id,
            ]);
        }

        return new Response('', 302, ['Location' => '/admin/flags/approvals']);
    }
}
