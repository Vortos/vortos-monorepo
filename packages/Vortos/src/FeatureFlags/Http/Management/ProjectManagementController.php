<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Http\Management;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;
use Vortos\Auth\Identity\CurrentUserProvider;
use Vortos\FeatureFlags\Authz\Management\ManagementAuthzGateInterface;
use Vortos\FeatureFlags\Http\RateLimit\FlagRateLimitService;
use Vortos\FeatureFlags\Project;
use Vortos\FeatureFlags\Storage\ProjectStorageInterface;
use Vortos\Http\Attribute\AsController;
use Vortos\Http\Exception\NotFoundException;
use Vortos\Http\JsonResponse;
use Vortos\Http\Request;

#[AsController]
final class ProjectManagementController
{
    public function __construct(
        private readonly ProjectStorageInterface $storage,
        private readonly ManagementAuthzGateInterface $authz,
        private readonly FlagRateLimitService $rateLimit,
        private readonly ManagementResponseFactory $response,
        private readonly CurrentUserProvider $currentUser,
    ) {}

    #[Route('/api/management/v1/projects', name: 'vortos.management.projects.list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $this->authz->requirePermission('flags.read.any');
        $this->rateLimit->checkManagement($this->currentUser->get()->id());

        $projects = $this->storage->findAll();
        $items    = array_map(fn(Project $p) => $p->toArray(), $projects);

        return $this->response->list($items, null, count($items));
    }

    #[Route('/api/management/v1/projects', name: 'vortos.management.projects.create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $this->authz->requirePermission('flags.admin.any');
        $actor = $this->currentUser->get();
        $this->rateLimit->checkManagement($actor->id());

        $body = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $name = (string) ($body['name'] ?? '');

        if ($name === '') {
            return new JsonResponse(['error' => 'name is required'], 422);
        }

        $now     = new \DateTimeImmutable();
        $project = new Project(
            id:          Uuid::v7()->toRfc4122(),
            name:        $name,
            slug:        Project::slugify($name),
            description: (string) ($body['description'] ?? ''),
            createdAt:   $now,
            updatedAt:   $now,
        );

        $this->storage->save($project);

        return $this->response->created($project->toArray());
    }

    #[Route('/api/management/v1/projects/{slug}', name: 'vortos.management.projects.delete', methods: ['DELETE'])]
    public function delete(string $slug): Response
    {
        $this->authz->requirePermission('flags.admin.any');
        $actor = $this->currentUser->get();
        $this->rateLimit->checkManagement($actor->id());

        $project = $this->storage->findBySlug($slug);
        if ($project === null) {
            throw new NotFoundException(sprintf('Project "%s" not found.', $slug));
        }

        $this->storage->delete($slug);

        return $this->response->noContent();
    }
}
