<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Http\Management;

use Symfony\Component\Routing\Attribute\Route;
use Vortos\Auth\Identity\CurrentUserProvider;
use Vortos\Cqrs\Validation\VortosValidator;
use Vortos\FeatureFlags\ChangeRequest\Approval;
use Vortos\FeatureFlags\ChangeRequest\ChangeRequest;
use Vortos\FeatureFlags\ChangeRequest\ChangeRequestConflictException;
use Vortos\FeatureFlags\ChangeRequest\ChangeRequestService;
use Vortos\FeatureFlags\ChangeRequest\ChangeRequestStatus;
use Vortos\FeatureFlags\ChangeRequest\Rejection;
use Vortos\FeatureFlags\ChangeRequest\Storage\ChangeRequestStorageInterface;
use Vortos\FeatureFlags\Authz\Management\ManagementAuthzGateInterface;
use Vortos\FeatureFlags\Http\Management\CursorEncoder;
use Vortos\FeatureFlags\Http\Management\Request\CreateChangeRequestRequest;
use Vortos\FeatureFlags\Http\Management\Request\VoteChangeRequestRequest;
use Vortos\FeatureFlags\Http\RateLimit\FlagRateLimitService;
use Vortos\Http\Attribute\AsController;
use Vortos\Http\Exception\HttpException;
use Vortos\Http\Exception\NotFoundException;
use Vortos\Http\JsonResponse;
use Vortos\Http\Request;

#[AsController]
final class ChangeRequestController
{
    public function __construct(
        private readonly ChangeRequestService $service,
        private readonly ChangeRequestStorageInterface $storage,
        private readonly ManagementAuthzGateInterface $authz,
        private readonly FlagRateLimitService $rateLimit,
        private readonly ManagementResponseFactory $response,
        private readonly CurrentUserProvider $currentUser,
        private readonly VortosValidator $validator,
    ) {}

    #[Route('/api/management/v1/change-requests', name: 'vortos.management.change_requests.list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $this->authz->requirePermission('flags.read.any');
        $this->rateLimit->checkManagement($this->currentUser->get()->id());

        $flagName    = (string) $request->query->get('flagName', '');
        $projectId   = (string) $request->query->get('projectId', '');
        $environment = (string) $request->query->get('environment', '');
        $statusRaw   = $request->query->get('status');
        $status      = is_string($statusRaw) && $statusRaw !== '' ? ChangeRequestStatus::tryFrom($statusRaw) : null;
        $cursor      = $request->query->has('cursor') ? (string) $request->query->get('cursor') : null;
        $limit       = min(100, max(1, (int) $request->query->get('limit', 50)));

        if ($flagName !== '') {
            // Per-flag view: environment is required to scope the flag's change requests.
            if ($environment === '') {
                throw new HttpException(422, 'environment query parameter is required when flagName is given.');
            }
            $rows = $this->storage->findByFlag($flagName, $projectId !== '' ? $projectId : 'default', $environment, $status, $cursor, $limit + 1);
        } else {
            // Global approvals inbox: change requests across all flags, optionally filtered.
            $rows = $this->storage->findRecent($status, $environment !== '' ? $environment : null, $projectId !== '' ? $projectId : null, $cursor, $limit + 1);
        }

        $nextCursor = null;
        if (count($rows) > $limit) {
            array_pop($rows);
            $last       = end($rows);
            $nextCursor = $last !== false ? CursorEncoder::encode($last->id(), $last->requestedAt()) : null;
        }

        $items = array_map(fn(ChangeRequest $cr) => $this->serialize($cr), $rows);

        return $this->response->list($items, $nextCursor, count($items));
    }

    #[Route('/api/management/v1/change-requests', name: 'vortos.management.change_requests.create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $this->authz->requirePermission('flags.write.any');
        $actor = $this->currentUser->get();
        $this->rateLimit->checkManagement($actor->id());

        $dto = CreateChangeRequestRequest::fromRequest($request, $this->validator);

        $cr = $this->guardDomain(fn() => $this->service->create(
            $dto->flagName,
            $dto->projectId,
            $dto->environment,
            $dto->changeType,
            $dto->payload,
            $dto->reason,
            $actor->id(),
            $dto->applyAt,
        ));

        return $this->response->created($this->serialize($cr));
    }

    #[Route('/api/management/v1/change-requests/{id}', name: 'vortos.management.change_requests.show', methods: ['GET'])]
    public function show(string $id): JsonResponse
    {
        $this->authz->requirePermission('flags.read.any');
        $this->rateLimit->checkManagement($this->currentUser->get()->id());

        $cr = $this->storage->findById($id);
        if ($cr === null) {
            throw new NotFoundException(sprintf('Change request "%s" not found.', $id));
        }

        return $this->response->ok($this->serialize($cr));
    }

    #[Route('/api/management/v1/change-requests/{id}/vote', name: 'vortos.management.change_requests.vote', methods: ['POST'])]
    public function vote(string $id, Request $request): JsonResponse
    {
        $this->authz->requirePermission('flags.approve.any');
        $actor = $this->currentUser->get();
        $this->rateLimit->checkManagement($actor->id());

        $dto = VoteChangeRequestRequest::fromRequest($request, $this->validator);

        $cr = $this->guardDomain(fn() => $this->service->vote($id, $actor->id(), $dto->approve, $dto->reason));

        return $this->response->ok($this->serialize($cr));
    }

    #[Route('/api/management/v1/change-requests/{id}/cancel', name: 'vortos.management.change_requests.cancel', methods: ['POST'])]
    public function cancel(string $id): JsonResponse
    {
        $this->authz->requirePermission('flags.write.any');
        $actor = $this->currentUser->get();
        $this->rateLimit->checkManagement($actor->id());

        $cr = $this->guardDomain(fn() => $this->service->cancel($id, $actor->id()));

        return $this->response->ok($this->serialize($cr));
    }

    #[Route('/api/management/v1/change-requests/{id}/apply', name: 'vortos.management.change_requests.apply', methods: ['POST'])]
    public function apply(string $id): JsonResponse
    {
        $this->authz->requirePermission('flags.publish.any');
        $actor = $this->currentUser->get();
        $this->rateLimit->checkManagement($actor->id());

        try {
            $cr = $this->service->apply($id, $actor->id());
        } catch (ChangeRequestConflictException $e) {
            return new JsonResponse([
                'error'                       => 'conflict',
                'message'                     => $e->getMessage(),
                'conflictingChangeRequestIds' => $e->conflictingIds,
            ], 409);
        } catch (\DomainException $e) {
            throw new HttpException(422, $e->getMessage(), [], $e);
        }

        return $this->response->ok($this->serialize($cr));
    }

    /**
     * @template T
     * @param callable():T $work
     * @return T
     */
    private function guardDomain(callable $work): mixed
    {
        try {
            return $work();
        } catch (\DomainException $e) {
            throw new HttpException(422, $e->getMessage(), [], $e);
        }
    }

    private function serialize(ChangeRequest $cr): array
    {
        return [
            'id'                => $cr->id(),
            'flagName'          => $cr->flagName(),
            'projectId'         => $cr->projectId(),
            'environment'       => $cr->environment(),
            'changeType'        => $cr->changeType()->value,
            'payload'           => $cr->payload(),
            'reason'            => $cr->reason(),
            'requestedBy'       => $cr->requestedBy(),
            'requestedAt'       => $cr->requestedAt()->format(\DateTimeInterface::ATOM),
            'status'            => $cr->status()->value,
            'requiredApprovals' => $cr->requiredApprovals(),
            'approvals'         => array_map(fn(Approval $a) => $a->toArray(), $cr->approvals()),
            'rejections'        => array_map(fn(Rejection $r) => $r->toArray(), $cr->rejections()),
            'applyAt'           => $cr->applyAt()?->format(\DateTimeInterface::ATOM),
            'expiresAt'         => $cr->expiresAt()->format(\DateTimeInterface::ATOM),
            'appliedAt'         => $cr->appliedAt()?->format(\DateTimeInterface::ATOM),
            'appliedBy'         => $cr->appliedBy(),
        ];
    }
}
