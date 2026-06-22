<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Http\Management;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Vortos\Auth\Identity\CurrentUserProvider;
use Vortos\Cqrs\Validation\VortosValidator;
use Vortos\FeatureFlags\Authz\Management\ManagementAuthzGateInterface;
use Vortos\FeatureFlags\Http\Management\Request\IssueSdkKeyRequest;
use Vortos\FeatureFlags\Http\RateLimit\FlagRateLimitService;
use Vortos\FeatureFlags\SdkKey\SdkKey;
use Vortos\FeatureFlags\SdkKey\SdkKeyService;
use Vortos\FeatureFlags\SdkKey\Storage\SdkKeyStorageInterface;
use Vortos\Http\Attribute\AsController;
use Vortos\Http\Exception\NotFoundException;
use Vortos\Http\JsonResponse;
use Vortos\Http\Request;

#[AsController]
final class SdkKeyManagementController
{
    public function __construct(
        private readonly SdkKeyService $sdkKeyService,
        private readonly SdkKeyStorageInterface $sdkKeyStorage,
        private readonly ManagementAuthzGateInterface $authz,
        private readonly FlagRateLimitService $rateLimit,
        private readonly ManagementResponseFactory $response,
        private readonly CurrentUserProvider $currentUser,
        private readonly VortosValidator $validator,
    ) {}

    #[Route('/api/management/v1/keys', name: 'vortos.management.sdk_keys.list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $this->authz->requirePermission('flags.admin.any');
        $this->rateLimit->checkManagement($this->currentUser->get()->id());

        $projectId   = $request->query->get('projectId', '');
        $environment = $request->query->get('environment', '');

        $keys  = $this->sdkKeyStorage->findByProjectAndEnv($projectId, $environment);
        $items = array_map(fn(SdkKey $k) => $this->serializeKeyMeta($k), $keys);

        return $this->response->list($items, null, count($items));
    }

    #[Route('/api/management/v1/keys', name: 'vortos.management.sdk_keys.issue', methods: ['POST'])]
    public function issue(Request $request): JsonResponse
    {
        $this->authz->requirePermission('flags.admin.any');
        $actor = $this->currentUser->get();
        $this->rateLimit->checkManagement($actor->id());

        $dto    = IssueSdkKeyRequest::fromRequest($request, $this->validator);
        $result = $this->sdkKeyService->issue(
            $dto->name,
            $dto->projectId,
            $dto->environment,
            $dto->kind,
            $actor->id(),
            $dto->ipAllowlist,
            $dto->expiresAt,
        );

        return $this->response->created([
            'rawKey' => $result['rawKey'],
            'key'    => $this->serializeKeyMeta($result['sdkKey']),
        ]);
    }

    #[Route('/api/management/v1/keys/{id}/rotate', name: 'vortos.management.sdk_keys.rotate', methods: ['POST'])]
    public function rotate(string $id): JsonResponse
    {
        $this->authz->requirePermission('flags.admin.any');
        $actor = $this->currentUser->get();
        $this->rateLimit->checkManagement($actor->id());

        $existing = $this->sdkKeyStorage->findById($id);
        if ($existing === null) {
            throw new NotFoundException(sprintf('SDK key "%s" not found.', $id));
        }

        $result = $this->sdkKeyService->rotate($id, $actor->id());

        return $this->response->created([
            'rawKey' => $result['rawKey'],
            'key'    => $this->serializeKeyMeta($result['sdkKey']),
        ]);
    }

    #[Route('/api/management/v1/keys/{id}', name: 'vortos.management.sdk_keys.revoke', methods: ['DELETE'])]
    public function revoke(string $id): Response
    {
        $this->authz->requirePermission('flags.admin.any');
        $actor = $this->currentUser->get();
        $this->rateLimit->checkManagement($actor->id());

        $existing = $this->sdkKeyStorage->findById($id);
        if ($existing === null) {
            throw new NotFoundException(sprintf('SDK key "%s" not found.', $id));
        }

        $this->sdkKeyService->revoke($id, $actor->id());

        return $this->response->noContent();
    }

    /** Serializes key metadata without exposing the hash or raw key. */
    private function serializeKeyMeta(SdkKey $key): array
    {
        return [
            'id'              => $key->id,
            'name'            => $key->name,
            'keyPrefix'       => $key->keyPrefix,
            'kind'            => $key->kind,
            'projectId'       => $key->projectId,
            'environment'     => $key->environment,
            'createdAt'       => $key->createdAt->format(\DateTimeInterface::ATOM),
            'createdBy'       => $key->createdBy,
            'rotatingTo'      => $key->successorKeyId,
            'gracePeriodEndsAt' => $key->gracePeriodEndsAt?->format(\DateTimeInterface::ATOM),
            'expiresAt'       => $key->expiresAt?->format(\DateTimeInterface::ATOM),
            'revokedAt'       => $key->revokedAt?->format(\DateTimeInterface::ATOM),
            'lastUsedAt'      => $key->lastUsedAt?->format(\DateTimeInterface::ATOM),
            'isActive'        => $key->isActive(),
        ];
    }
}
