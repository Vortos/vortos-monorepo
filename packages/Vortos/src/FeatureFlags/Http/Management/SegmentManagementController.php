<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Http\Management;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;
use Vortos\Auth\Identity\CurrentUserProvider;
use Vortos\Cqrs\Validation\VortosValidator;
use Vortos\FeatureFlags\Authz\Management\ManagementAuthzGateInterface;
use Vortos\FeatureFlags\FlagRule;
use Vortos\FeatureFlags\Http\Management\Request\CreateSegmentRequest;
use Vortos\FeatureFlags\Http\Management\Request\UpdateSegmentRequest;
use Vortos\FeatureFlags\Http\RateLimit\FlagRateLimitService;
use Vortos\FeatureFlags\Segment;
use Vortos\FeatureFlags\Storage\SegmentStorageInterface;
use Vortos\Http\Attribute\AsController;
use Vortos\Http\Exception\NotFoundException;
use Vortos\Http\JsonResponse;
use Vortos\Http\Request;

#[AsController]
final class SegmentManagementController
{
    public function __construct(
        private readonly SegmentStorageInterface $storage,
        private readonly ManagementAuthzGateInterface $authz,
        private readonly FlagRateLimitService $rateLimit,
        private readonly ManagementResponseFactory $response,
        private readonly CurrentUserProvider $currentUser,
        private readonly VortosValidator $validator,
    ) {}

    #[Route('/api/management/v1/segments', name: 'vortos.management.segments.list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $this->authz->requirePermission('flags.read.any');
        $this->rateLimit->checkManagement($this->currentUser->get()->id());

        $segments = $this->storage->findAll();
        $items    = array_map(fn(Segment $s) => $s->toArray(), $segments);

        return $this->response->list($items, null, count($items));
    }

    #[Route('/api/management/v1/segments', name: 'vortos.management.segments.create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $this->authz->requirePermission('flags.write.any');
        $actor = $this->currentUser->get();
        $this->rateLimit->checkManagement($actor->id());

        $dto   = CreateSegmentRequest::fromRequest($request, $this->validator);
        $rules = array_map(fn(array $r) => FlagRule::fromArray($r), $dto->rules);
        $now   = new \DateTimeImmutable();

        $segment = new Segment(
            id:          Uuid::v7()->toRfc4122(),
            name:        $dto->name,
            description: $dto->description,
            rules:       $rules,
            createdAt:   $now,
            updatedAt:   $now,
            projectId:   $dto->projectId,
        );

        $this->storage->save($segment);

        return $this->response->created($segment->toArray());
    }

    #[Route('/api/management/v1/segments/{id}', name: 'vortos.management.segments.show', methods: ['GET'])]
    public function show(string $id): JsonResponse
    {
        $this->authz->requirePermission('flags.read.any');
        $this->rateLimit->checkManagement($this->currentUser->get()->id());

        $segment = $this->storage->findByName($id);
        if ($segment === null) {
            throw new NotFoundException(sprintf('Segment "%s" not found.', $id));
        }

        return $this->response->ok($segment->toArray());
    }

    #[Route('/api/management/v1/segments/{id}', name: 'vortos.management.segments.update', methods: ['PATCH'])]
    public function update(string $id, Request $request): JsonResponse
    {
        $this->authz->requirePermission('flags.write.any');
        $actor = $this->currentUser->get();
        $this->rateLimit->checkManagement($actor->id());

        $segment = $this->storage->findByName($id);
        if ($segment === null) {
            throw new NotFoundException(sprintf('Segment "%s" not found.', $id));
        }

        $dto   = UpdateSegmentRequest::fromRequest($request, $this->validator);
        $rules = $dto->rules !== null
            ? array_map(fn(array $r) => FlagRule::fromArray($r), $dto->rules)
            : $segment->rules;

        $updated = new Segment(
            id:          $segment->id,
            name:        $dto->name ?? $segment->name,
            description: $dto->description ?? $segment->description,
            rules:       $rules,
            createdAt:   $segment->createdAt,
            updatedAt:   new \DateTimeImmutable(),
            projectId:   $segment->projectId,
        );

        $this->storage->save($updated);

        return $this->response->ok($updated->toArray());
    }

    #[Route('/api/management/v1/segments/{id}', name: 'vortos.management.segments.delete', methods: ['DELETE'])]
    public function delete(string $id): Response
    {
        $this->authz->requirePermission('flags.write.any');
        $actor = $this->currentUser->get();
        $this->rateLimit->checkManagement($actor->id());

        $segment = $this->storage->findByName($id);
        if ($segment === null) {
            throw new NotFoundException(sprintf('Segment "%s" not found.', $id));
        }

        $this->storage->delete($id);

        return $this->response->noContent();
    }
}
