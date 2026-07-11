<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Http\Management;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;
use Vortos\Auth\Identity\CurrentUserProvider;
use Vortos\Cqrs\Validation\VortosValidator;
use Vortos\FeatureFlags\Application\FlagPromotionService;
use Vortos\FeatureFlags\Application\FlagWriteService;
use Vortos\FeatureFlags\Authz\Management\ManagementAuthzGateInterface;
use Vortos\FeatureFlags\Exception\FlagNotFoundException;
use Vortos\FeatureFlags\FeatureFlag;
use Vortos\FeatureFlags\FlagKind;
use Vortos\FeatureFlags\FlagLifecycleState;
use Vortos\FeatureFlags\FlagRule;
use Vortos\FeatureFlags\FlagScopeContext;
use Vortos\FeatureFlags\FlagValueType;
use Vortos\FeatureFlags\Http\Management\Interceptor\ChangeRequestInterceptorInterface;
use Vortos\FeatureFlags\Http\Management\Request\CreateFlagRequest;
use Vortos\FeatureFlags\Http\Management\Request\PromoteFlagRequest;
use Vortos\FeatureFlags\Http\Management\Request\UpdateFlagRequest;
use Vortos\FeatureFlags\Http\Management\Request\UpdateRulesRequest;
use Vortos\FeatureFlags\Http\Management\Request\UpdateScheduleRequest;
use Vortos\FeatureFlags\Http\Management\Request\UpdateVariantsRequest;
use Vortos\FeatureFlags\Http\RateLimit\FlagRateLimitService;
use Vortos\FeatureFlags\ProjectContext;
use Vortos\FeatureFlags\RolloutSchedule;
use Vortos\FeatureFlags\Storage\FlagStorageInterface;
use Vortos\Http\Attribute\AsController;
use Vortos\Http\Exception\NotFoundException;
use Vortos\Http\JsonResponse;
use Vortos\Http\Request;

#[AsController]
final class FlagManagementController
{
    public function __construct(
        private readonly FlagStorageInterface $storage,
        private readonly FlagWriteService $writeService,
        private readonly FlagPromotionService $promotionService,
        private readonly ManagementAuthzGateInterface $authz,
        private readonly FlagRateLimitService $rateLimit,
        private readonly ManagementResponseFactory $response,
        private readonly CurrentUserProvider $currentUser,
        private readonly FlagScopeContext $scopeContext,
        private readonly ProjectContext $projectContext,
        private readonly ChangeRequestInterceptorInterface $changeRequestInterceptor,
        private readonly VortosValidator $validator,
    ) {}

    #[Route('/api/management/v1/flags', name: 'vortos.management.flags.list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $this->authz->requirePermission('flags.read.any');
        $this->rateLimit->checkManagement($this->currentUser->get()->id());

        $flags = $this->storage->findAll();

        $items = array_map(fn(FeatureFlag $f) => $this->serializeFlag($f), $flags);

        return $this->response->list($items, null, count($items));
    }

    #[Route('/api/management/v1/flags', name: 'vortos.management.flags.create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $this->authz->requirePermission('flags.write.any');
        $actor = $this->currentUser->get();
        $this->rateLimit->checkManagement($actor->id());

        $dto = CreateFlagRequest::fromRequest($request, $this->validator);

        if ($dto->environment !== null) {
            $this->scopeContext->withEnvironment($dto->environment);
        }
        $this->projectContext->withProject($dto->projectId);

        $now  = new \DateTimeImmutable();
        $flag = new FeatureFlag(
            id:          Uuid::v7()->toRfc4122(),
            name:        $dto->name,
            description: $dto->description,
            enabled:     false,
            rules:       [],
            variants:    null,
            createdAt:   $now,
            updatedAt:   $now,
            kind:        $dto->kind !== null ? FlagKind::from($dto->kind) : FlagKind::Release,
            bucketBy:    $dto->bucketBy ?? FeatureFlag::BUCKET_BY_USER,
            owner:       $dto->owner,
        );

        $aggregate = $this->writeService->create($flag, $actor->id());

        return $this->response->created($this->serializeFlag($aggregate->state()));
    }

    #[Route('/api/management/v1/flags/{name}', name: 'vortos.management.flags.show', methods: ['GET'])]
    public function show(string $name): JsonResponse
    {
        $this->authz->requirePermission('flags.read.any');
        $this->rateLimit->checkManagement($this->currentUser->get()->id());

        $flag = $this->storage->findByName($name);

        if ($flag === null) {
            throw new NotFoundException(sprintf('Flag "%s" not found.', $name));
        }

        return $this->response->ok($this->serializeFlag($flag));
    }

    #[Route('/api/management/v1/flags/{name}', name: 'vortos.management.flags.update', methods: ['PATCH'])]
    public function update(string $name, Request $request): JsonResponse
    {
        $this->authz->requirePermission('flags.write.any');
        $actor = $this->currentUser->get();
        $this->rateLimit->checkManagement($actor->id());

        $existing = $this->storage->findByName($name);
        if ($existing === null) {
            throw new NotFoundException(sprintf('Flag "%s" not found.', $name));
        }

        $dto = UpdateFlagRequest::fromRequest($request, $this->validator);

        // Metadata-only PATCH. Enabling/disabling a flag is a distinct, audited state
        // transition with its own endpoints (POST .../enable, POST .../disable) and its own
        // change-request interception — a metadata edit must never flip a flag ON as a side
        // effect. Apply only the fields provided; an empty PATCH is a no-op that echoes back
        // the current state.
        $flag = null;
        if ($dto->owner !== null) {
            $flag = $this->writeService->setOwner($name, $dto->owner, $actor->id());
        }

        $state = $flag?->state() ?? $existing;

        return $this->response->ok($this->serializeFlag($state));
    }

    #[Route('/api/management/v1/flags/{name}', name: 'vortos.management.flags.delete', methods: ['DELETE'])]
    public function delete(string $name): Response
    {
        $this->authz->requirePermission('flags.write.any');
        $actor = $this->currentUser->get();
        $this->rateLimit->checkManagement($actor->id());

        $existing = $this->storage->findByName($name);
        if ($existing === null) {
            throw new NotFoundException(sprintf('Flag "%s" not found.', $name));
        }

        $this->writeService->changeLifecycle($name, FlagLifecycleState::Archived, $actor->id());

        return $this->response->noContent();
    }

    #[Route('/api/management/v1/flags/{name}/enable', name: 'vortos.management.flags.enable', methods: ['POST'])]
    public function enable(string $name): JsonResponse
    {
        $this->authz->requirePermission('flags.write.any');
        $actor = $this->currentUser->get();
        $this->rateLimit->checkManagement($actor->id());

        if ($this->changeRequestInterceptor->isProtected($name, $this->scopeContext->environment())) {
            return new JsonResponse(['message' => 'Change request required for this environment.'], 202);
        }

        $existing = $this->storage->findByName($name);
        if ($existing === null) {
            throw new NotFoundException(sprintf('Flag "%s" not found.', $name));
        }

        $flag = $this->writeService->enable($name, $actor->id());

        return $this->response->ok($this->serializeFlag($flag->state()));
    }

    #[Route('/api/management/v1/flags/{name}/disable', name: 'vortos.management.flags.disable', methods: ['POST'])]
    public function disable(string $name): JsonResponse
    {
        $this->authz->requirePermission('flags.write.any');
        $actor = $this->currentUser->get();
        $this->rateLimit->checkManagement($actor->id());

        if ($this->changeRequestInterceptor->isProtected($name, $this->scopeContext->environment())) {
            return new JsonResponse(['message' => 'Change request required for this environment.'], 202);
        }

        $existing = $this->storage->findByName($name);
        if ($existing === null) {
            throw new NotFoundException(sprintf('Flag "%s" not found.', $name));
        }

        $flag = $this->writeService->disable($name, $actor->id());

        return $this->response->ok($this->serializeFlag($flag->state()));
    }

    #[Route('/api/management/v1/flags/{name}/rules', name: 'vortos.management.flags.rules', methods: ['PUT'])]
    public function replaceRules(string $name, Request $request): JsonResponse
    {
        $this->authz->requirePermission('flags.write.any');
        $actor = $this->currentUser->get();
        $this->rateLimit->checkManagement($actor->id());

        $existing = $this->storage->findByName($name);
        if ($existing === null) {
            throw new NotFoundException(sprintf('Flag "%s" not found.', $name));
        }

        $dto   = UpdateRulesRequest::fromRequest($request, $this->validator);
        $rules = array_map(fn(array $r) => FlagRule::fromArray($r), $dto->rules);
        $flag  = $this->writeService->changeRules($name, $rules, $actor->id());

        return $this->response->ok($this->serializeFlag($flag->state()));
    }

    #[Route('/api/management/v1/flags/{name}/variants', name: 'vortos.management.flags.variants', methods: ['PUT'])]
    public function replaceVariants(string $name, Request $request): JsonResponse
    {
        $this->authz->requirePermission('flags.write.any');
        $actor = $this->currentUser->get();
        $this->rateLimit->checkManagement($actor->id());

        $existing = $this->storage->findByName($name);
        if ($existing === null) {
            throw new NotFoundException(sprintf('Flag "%s" not found.', $name));
        }

        $dto  = UpdateVariantsRequest::fromRequest($request, $this->validator);
        $flag = $this->writeService->changeVariants($name, $dto->variants, $actor->id());

        return $this->response->ok($this->serializeFlag($flag->state()));
    }

    #[Route('/api/management/v1/flags/{name}/schedule', name: 'vortos.management.flags.schedule', methods: ['PUT'])]
    public function setSchedule(string $name, Request $request): JsonResponse
    {
        $this->authz->requirePermission('flags.write.any');
        $actor = $this->currentUser->get();
        $this->rateLimit->checkManagement($actor->id());

        $existing = $this->storage->findByName($name);
        if ($existing === null) {
            throw new NotFoundException(sprintf('Flag "%s" not found.', $name));
        }

        $dto      = UpdateScheduleRequest::fromRequest($request, $this->validator);
        $schedule = $dto->schedule !== null ? RolloutSchedule::fromArray($dto->schedule) : null;
        $flag     = $this->writeService->schedule($name, $schedule, $actor->id());

        return $this->response->ok($this->serializeFlag($flag->state()));
    }

    #[Route('/api/management/v1/flags/{name}/promote', name: 'vortos.management.flags.promote', methods: ['POST'])]
    public function promote(string $name, Request $request): JsonResponse
    {
        $this->authz->requirePermission('flags.publish.any');
        $actor = $this->currentUser->get();
        $this->rateLimit->checkManagement($actor->id());

        $dto = PromoteFlagRequest::fromRequest($request, $this->validator);

        $this->promotionService->promote($name, $dto->fromEnvironment, $dto->toEnvironment, $actor->id());

        return $this->response->ok(['promoted' => true, 'from' => $dto->fromEnvironment, 'to' => $dto->toEnvironment]);
    }

    private function serializeFlag(FeatureFlag $flag): array
    {
        return [
            'id'          => $flag->id,
            'name'        => $flag->name,
            'description' => $flag->description,
            'enabled'     => $flag->enabled,
            'kind'        => $flag->kind->value,
            'valueType'   => $flag->valueType->value,
            'bucketBy'    => $flag->bucketBy,
            'projectId'   => $flag->projectId,
            'environment' => $flag->environment,
            'lifecycle'   => $flag->lifecycle->value,
            'owner'       => $flag->owner,
            // Targeting configuration — exposed so a management UI can render and round-trip
            // the current rules/variants/schedule when editing (the PUT endpoints replace
            // these wholesale, so a client must be able to read them first).
            'rules'       => array_map(static fn(FlagRule $r) => $r->toArray(), $flag->rules),
            'variants'    => $flag->variants,
            'schedule'    => $flag->schedule?->toArray(),
            'payload'     => $flag->payload,
            'defaultValue' => $flag->defaultValue()->encode(),
            'expiresAt'   => $flag->expiresAt?->format(\DateTimeInterface::ATOM),
            'createdAt'   => $flag->createdAt->format(\DateTimeInterface::ATOM),
            'updatedAt'   => $flag->updatedAt->format(\DateTimeInterface::ATOM),
        ];
    }
}
