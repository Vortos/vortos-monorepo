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
use Vortos\FeatureFlags\FlagValue;
use Vortos\FeatureFlags\FlagValueType;
use Vortos\FeatureFlags\Prerequisite;
use Vortos\FeatureFlags\Http\Management\Interceptor\ChangeRequestInterceptorInterface;
use Vortos\FeatureFlags\Http\Management\Request\CreateFlagRequest;
use Vortos\FeatureFlags\Http\Management\Request\PromoteFlagRequest;
use Vortos\FeatureFlags\Http\Management\Request\UpdateFlagRequest;
use Vortos\FeatureFlags\Http\Management\Request\UpdateRulesRequest;
use Vortos\FeatureFlags\Http\Management\Request\UpdateScheduleRequest;
use Vortos\FeatureFlags\Http\Management\Request\UpdateVariantRulesRequest;
use Vortos\FeatureFlags\Http\Management\Request\UpdateVariantsRequest;
use Vortos\FeatureFlags\Http\RateLimit\FlagRateLimitService;
use Vortos\FeatureFlags\ProjectContext;
use Vortos\FeatureFlags\RolloutSchedule;
use Vortos\FeatureFlags\Storage\FlagEnvironmentStateStorageInterface;
use Vortos\FeatureFlags\Storage\FlagStorageInterface;
use Vortos\Http\Attribute\AsController;
use Vortos\Http\Exception\BadRequestException;
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
        private readonly ?FlagEnvironmentStateStorageInterface $envStateStorage = null,
    ) {}

    /** The environments a flag can hold independent state in. */
    private const ENVIRONMENTS = ['production', 'staging', 'development', 'test'];

    #[Route('/api/management/v1/flags', name: 'vortos.management.flags.list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $this->authz->requirePermission('flags.read.any');
        $this->rateLimit->checkManagement($this->currentUser->get()->id());

        $env   = $this->applyEnv($request);
        $all   = $this->storage->findAll();

        // Optional project scoping: ?project=<id> narrows to one workspace; omitting it (or
        // the "all" sentinel) returns every project's flags. Backward-compatible with callers
        // that never send the parameter.
        $project = (string) $request->query->get('project', '');
        if ($project !== '' && $project !== 'all') {
            $all = array_values(array_filter($all, static fn(FeatureFlag $f) => $f->projectId === $project));
        }

        $flags = array_map(fn(FeatureFlag $f) => $this->composeForEnv($f, $env), $all);
        $items = array_map(fn(FeatureFlag $f) => $this->serializeFlag($f), $flags);

        return $this->response->list($items, null, count($items));
    }

    #[Route('/api/management/v1/environments', name: 'vortos.management.environments.list', methods: ['GET'])]
    public function environments(): JsonResponse
    {
        $this->authz->requirePermission('flags.read.any');
        $this->rateLimit->checkManagement($this->currentUser->get()->id());

        return $this->response->ok(array_map(
            static fn(string $e) => ['name' => $e, 'default' => $e === FlagScopeContext::ENV_PRODUCTION],
            self::ENVIRONMENTS,
        ));
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
    public function show(string $name, Request $request): JsonResponse
    {
        $this->authz->requirePermission('flags.read.any');
        $this->rateLimit->checkManagement($this->currentUser->get()->id());

        $env  = $this->applyEnv($request);
        $flag = $this->storage->findByName($name);

        if ($flag === null) {
            throw new NotFoundException(sprintf('Flag "%s" not found.', $name));
        }

        return $this->response->ok($this->serializeFlag($this->composeForEnv($flag, $env)));
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

        $this->applyEnv($request);

        $dto = UpdateFlagRequest::fromRequest($request, $this->validator);
        // Presence map from the raw body: distinguishes "sent null" (clear the field) from
        // "omitted" (leave unchanged) — the DTO alone can't tell them apart.
        $raw = json_decode((string) $request->getContent(), true);
        $raw = is_array($raw) ? $raw : [];
        $has = static fn(string $k): bool => array_key_exists($k, $raw);

        // Metadata edit only. Enabling/disabling is a distinct, audited transition with its
        // own endpoints (and change-request interception) — a metadata PATCH must never flip
        // a flag on/off as a side effect. Each field applies only when present.
        if ($dto->owner !== null) {
            $this->writeService->setOwner($name, $dto->owner, $actor->id());
        }
        if ($dto->lifecycle !== null) {
            $this->writeService->changeLifecycle($name, FlagLifecycleState::from($dto->lifecycle), $actor->id());
        }
        if ($has('expiresAt')) {
            $expiresAt = $dto->expiresAt !== null ? new \DateTimeImmutable($dto->expiresAt) : null;
            $this->writeService->setExpiry($name, $expiresAt, $actor->id());
        }

        $prerequisites = null;
        if ($has('prerequisites') && is_array($dto->prerequisites)) {
            $prerequisites = array_map(static fn(array $p) => Prerequisite::fromArray($p), $dto->prerequisites);
            // A prerequisite must reference a real flag, and never itself (which would
            // deadlock evaluation). Reject with 400 so the console can surface a clear error.
            foreach ($prerequisites as $prereq) {
                if ($prereq->flag === $name) {
                    throw new BadRequestException(sprintf('A flag cannot be a prerequisite of itself ("%s").', $name));
                }
                if ($this->storage->findByName($prereq->flag) === null) {
                    throw new BadRequestException(sprintf('Prerequisite flag "%s" does not exist.', $prereq->flag));
                }
            }
        }
        $defaultValue = null;
        if ($has('defaultValue') && $dto->defaultValue !== null) {
            $defaultValue = FlagValue::decode($existing->valueType, $dto->defaultValue);
        }

        $this->writeService->reconfigure(
            name:                  $name,
            actorId:               $actor->id(),
            description:           $dto->description,
            kind:                  $dto->kind !== null ? FlagKind::from($dto->kind) : null,
            bucketBy:              $dto->bucketBy,
            prerequisites:         $prerequisites,
            requiredScopeProvided: $has('requiredScope'),
            requiredScope:         $dto->requiredScope,
            payloadProvided:       $has('payload'),
            payload:               $dto->payload,
            defaultValueProvided:  $has('defaultValue'),
            defaultValue:          $defaultValue,
            layerProvided:         $has('layerId'),
            layerId:               $dto->layerId,
        );

        $updated = $this->storage->findByName($name) ?? $existing;

        return $this->response->ok($this->serializeFlag($updated));
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
    public function enable(string $name, Request $request): JsonResponse
    {
        $this->authz->requirePermission('flags.write.any');
        $actor = $this->currentUser->get();
        $this->rateLimit->checkManagement($actor->id());
        $this->applyEnv($request);

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
    public function disable(string $name, Request $request): JsonResponse
    {
        $this->authz->requirePermission('flags.write.any');
        $actor = $this->currentUser->get();
        $this->rateLimit->checkManagement($actor->id());
        $this->applyEnv($request);

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
        $this->applyEnv($request);

        if ($this->changeRequestInterceptor->isProtected($name, $this->scopeContext->environment())) {
            return new JsonResponse(['message' => 'Change request required for this environment.'], 202);
        }

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
        $this->applyEnv($request);

        if ($this->changeRequestInterceptor->isProtected($name, $this->scopeContext->environment())) {
            return new JsonResponse(['message' => 'Change request required for this environment.'], 202);
        }

        $existing = $this->storage->findByName($name);
        if ($existing === null) {
            throw new NotFoundException(sprintf('Flag "%s" not found.', $name));
        }

        $dto  = UpdateVariantsRequest::fromRequest($request, $this->validator);
        $flag = $this->writeService->changeVariants($name, $dto->variants, $actor->id());

        return $this->response->ok($this->serializeFlag($flag->state()));
    }

    #[Route('/api/management/v1/flags/{name}/variant-rules', name: 'vortos.management.flags.variant_rules', methods: ['PUT'])]
    public function replaceVariantRules(string $name, Request $request): JsonResponse
    {
        $this->authz->requirePermission('flags.write.any');
        $actor = $this->currentUser->get();
        $this->rateLimit->checkManagement($actor->id());
        $this->applyEnv($request);

        $existing = $this->storage->findByName($name);
        if ($existing === null) {
            throw new NotFoundException(sprintf('Flag "%s" not found.', $name));
        }

        $dto = UpdateVariantRulesRequest::fromRequest($request, $this->validator);
        $variantRules = $dto->variantRules === null ? null : array_map(
            static fn(array $rules) => array_map(static fn(array $r) => FlagRule::fromArray($r), $rules),
            $dto->variantRules,
        );
        $flag = $this->writeService->changeVariantRules($name, $variantRules, $actor->id());

        return $this->response->ok($this->serializeFlag($flag->state()));
    }

    #[Route('/api/management/v1/flags/{name}/schedule', name: 'vortos.management.flags.schedule', methods: ['PUT'])]
    public function setSchedule(string $name, Request $request): JsonResponse
    {
        $this->authz->requirePermission('flags.write.any');
        $actor = $this->currentUser->get();
        $this->rateLimit->checkManagement($actor->id());
        $this->applyEnv($request);

        if ($this->changeRequestInterceptor->isProtected($name, $this->scopeContext->environment())) {
            return new JsonResponse(['message' => 'Change request required for this environment.'], 202);
        }

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

    /** Read ?env= (default production), validate against the known set, set the scope, return it. */
    private function applyEnv(Request $request): string
    {
        $env = (string) $request->query->get('env', FlagScopeContext::ENV_PRODUCTION);
        if (!in_array($env, self::ENVIRONMENTS, true)) {
            $env = FlagScopeContext::ENV_PRODUCTION;
        }
        $this->scopeContext->withEnvironment($env);

        return $env;
    }

    /** Compose a definition with its per-env mutable state so reads reflect the selected env. */
    private function composeForEnv(FeatureFlag $definition, string $env): FeatureFlag
    {
        if ($this->envStateStorage === null) {
            return $definition->withEnvironment($env);
        }

        $state = $this->envStateStorage->findForFlag($definition->id, $env);

        return $state !== null
            ? FeatureFlag::compose($definition, $state)
            : $definition->withEnvironment($env);
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
            'variantRules' => $flag->variantRules !== null
                ? array_map(static fn(array $rules) => array_map(static fn(FlagRule $r) => $r->toArray(), $rules), $flag->variantRules)
                : null,
            'schedule'    => $flag->schedule?->toArray(),
            'prerequisites' => array_map(static fn(Prerequisite $p) => $p->toArray(), $flag->prerequisites),
            'requiredScope' => $flag->requiredScope,
            'layerId'     => $flag->layerId,
            'payload'     => $flag->payload,
            'defaultValue' => $flag->defaultValue()->encode(),
            'expiresAt'   => $flag->expiresAt?->format(\DateTimeInterface::ATOM),
            'createdAt'   => $flag->createdAt->format(\DateTimeInterface::ATOM),
            'updatedAt'   => $flag->updatedAt->format(\DateTimeInterface::ATOM),
        ];
    }
}
