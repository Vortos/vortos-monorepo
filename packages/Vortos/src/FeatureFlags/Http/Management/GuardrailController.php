<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Http\Management;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Vortos\Auth\Identity\CurrentUserProvider;
use Vortos\Cqrs\Validation\VortosValidator;
use Vortos\FeatureFlags\Authz\Management\ManagementAuthzGateInterface;
use Vortos\FeatureFlags\Guardrail\GuardrailAction;
use Vortos\FeatureFlags\Guardrail\GuardrailPolicy;
use Vortos\FeatureFlags\Guardrail\GuardrailPolicyService;
use Vortos\FeatureFlags\Http\Management\Request\CreateGuardrailPolicyRequest;
use Vortos\FeatureFlags\Http\RateLimit\FlagRateLimitService;
use Vortos\Http\Attribute\AsController;
use Vortos\Http\Exception\HttpException;
use Vortos\Http\Exception\NotFoundException;
use Vortos\Http\JsonResponse;
use Vortos\Http\Request;

#[AsController]
final class GuardrailController
{
    public function __construct(
        private readonly GuardrailPolicyService $service,
        private readonly ManagementAuthzGateInterface $authz,
        private readonly FlagRateLimitService $rateLimit,
        private readonly ManagementResponseFactory $response,
        private readonly CurrentUserProvider $currentUser,
        private readonly VortosValidator $validator,
    ) {}

    #[Route('/api/management/v1/guardrails', name: 'vortos.management.guardrails.list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $this->authz->requirePermission('flags.admin.any');
        $this->rateLimit->checkManagement($this->currentUser->get()->id());

        $flagName    = (string) $request->query->get('flagName', '');
        $projectId   = (string) $request->query->get('projectId', 'default');
        $environment = (string) $request->query->get('environment', '');

        if ($flagName === '' || $environment === '') {
            throw new HttpException(422, 'flagName and environment query parameters are required.');
        }

        $items = array_map(
            fn(GuardrailPolicy $p) => $this->serialize($p),
            $this->service->listForFlag($flagName, $projectId, $environment),
        );

        return $this->response->list($items, null, count($items));
    }

    #[Route('/api/management/v1/guardrails', name: 'vortos.management.guardrails.create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $this->authz->requirePermission('flags.admin.any');
        $actor = $this->currentUser->get();
        $this->rateLimit->checkManagement($actor->id());

        $dto = CreateGuardrailPolicyRequest::fromRequest($request, $this->validator);

        try {
            $policy = $this->service->create(
                $dto->flagName,
                $dto->projectId,
                $dto->environment,
                GuardrailAction::from($dto->action),
                $dto->conditions,
                $dto->consecutiveWindows,
                $dto->windowSeconds,
                $dto->cooldownSeconds,
                $actor->id(),
                $dto->pauseRampTargetPct,
                $dto->ackRequired,
            );
        } catch (\InvalidArgumentException $e) {
            throw new HttpException(422, $e->getMessage(), [], $e);
        }

        return $this->response->created($this->serialize($policy));
    }

    #[Route('/api/management/v1/guardrails/{id}', name: 'vortos.management.guardrails.show', methods: ['GET'])]
    public function show(string $id): JsonResponse
    {
        $this->authz->requirePermission('flags.admin.any');
        $this->rateLimit->checkManagement($this->currentUser->get()->id());

        $policy = $this->service->findById($id);
        if ($policy === null) {
            throw new NotFoundException(sprintf('Guardrail policy "%s" not found.', $id));
        }

        return $this->response->ok($this->serialize($policy));
    }

    #[Route('/api/management/v1/guardrails/{id}', name: 'vortos.management.guardrails.update', methods: ['PATCH'])]
    public function update(string $id, Request $request): JsonResponse
    {
        $this->authz->requirePermission('flags.admin.any');
        $actor = $this->currentUser->get();
        $this->rateLimit->checkManagement($actor->id());

        if ($this->service->findById($id) === null) {
            throw new NotFoundException(sprintf('Guardrail policy "%s" not found.', $id));
        }

        $decoded = json_decode((string) $request->getContent(), true);
        $changes = is_array($decoded) ? $decoded : [];

        try {
            $policy = $this->service->update($id, $changes, $actor->id());
        } catch (\InvalidArgumentException $e) {
            throw new HttpException(422, $e->getMessage(), [], $e);
        }

        return $this->response->ok($this->serialize($policy));
    }

    #[Route('/api/management/v1/guardrails/{id}/ack', name: 'vortos.management.guardrails.ack', methods: ['POST'])]
    public function acknowledge(string $id): JsonResponse
    {
        $this->authz->requirePermission('flags.admin.any');
        $actor = $this->currentUser->get();
        $this->rateLimit->checkManagement($actor->id());

        try {
            $policy = $this->service->acknowledge($id, $actor->id());
        } catch (\DomainException $e) {
            throw new HttpException(422, $e->getMessage(), [], $e);
        }

        return $this->response->ok($this->serialize($policy));
    }

    #[Route('/api/management/v1/guardrails/{id}', name: 'vortos.management.guardrails.delete', methods: ['DELETE'])]
    public function delete(string $id): Response
    {
        $this->authz->requirePermission('flags.admin.any');
        $this->rateLimit->checkManagement($this->currentUser->get()->id());

        if ($this->service->findById($id) === null) {
            throw new NotFoundException(sprintf('Guardrail policy "%s" not found.', $id));
        }

        $this->service->delete($id);

        return $this->response->noContent();
    }

    private function serialize(GuardrailPolicy $p): array
    {
        return $p->toArray();
    }
}
