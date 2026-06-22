<?php

declare(strict_types=1);

namespace Vortos\FeatureFlagsAdmin\Http\Fragment;

use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;
use Vortos\Auth\Identity\CurrentUserProvider;
use Vortos\FeatureFlags\Application\FlagWriteService;
use Vortos\FeatureFlags\Authz\Management\ManagementAuthzGateInterface;
use Vortos\FeatureFlags\FeatureFlag;
use Vortos\FeatureFlags\FlagKind;
use Vortos\FeatureFlags\FlagRule;
use Vortos\FeatureFlags\FlagScopeContext;
use Vortos\FeatureFlags\FlagValueType;
use Vortos\FeatureFlags\Http\Management\Interceptor\ChangeRequestInterceptorInterface;
use Vortos\FeatureFlags\Http\RateLimit\FlagRateLimitService;
use Vortos\FeatureFlags\ProjectContext;
use Vortos\FeatureFlags\ReadModel\FlagStateViewRepositoryInterface;
use Vortos\FeatureFlags\RolloutSchedule;
use Vortos\FeatureFlags\Storage\FlagStorageInterface;
use Vortos\FeatureFlags\Validation\FlagValidator;
use Vortos\FeatureFlagsAdmin\Rendering\TwigRenderer;
use Vortos\Http\Attribute\AsController;
use Vortos\Http\Exception\ForbiddenException;
use Vortos\Http\Exception\NotFoundException;
use Vortos\Http\Request;
use Vortos\Http\Response;

#[AsController]
final class FlagFragmentController
{
    public function __construct(
        private readonly TwigRenderer $renderer,
        private readonly FlagStorageInterface $storage,
        private readonly FlagStateViewRepositoryInterface $stateView,
        private readonly FlagWriteService $writeService,
        private readonly ManagementAuthzGateInterface $authz,
        private readonly CurrentUserProvider $currentUser,
        private readonly FlagRateLimitService $rateLimit,
        private readonly FlagValidator $validator,
        private readonly FlagScopeContext $scopeContext,
        private readonly ProjectContext $projectContext,
        private readonly ChangeRequestInterceptorInterface $changeRequestInterceptor,
    ) {}

    #[Route('/admin/flags/fragment/{name}/toggle', name: 'vortos.admin.flags.fragment.toggle', methods: ['POST'])]
    public function toggle(Request $request, string $name): Response
    {
        $this->authz->requirePermission('flags.write');
        $this->rateLimit->checkManagement($this->currentUser->get()->id());

        $env = $request->request->get('env', 'production');
        $this->scopeContext->withEnvironment($env);

        if ($this->changeRequestInterceptor->isProtected($name, $env)) {
            throw new ForbiddenException('This flag/environment requires a change request.');
        }

        $flag = $this->storage->findByName($name);
        if ($flag === null) {
            throw new NotFoundException("Flag '{$name}' not found.");
        }

        $actorId = $this->currentUser->get()->id();

        if ($flag->enabled) {
            $this->writeService->disable($name, $actorId, 'Toggled off via admin UI');
        } else {
            $this->writeService->enable($name, $actorId, 'Toggled on via admin UI');
        }

        $updatedView = $this->stateView->findByName($name, $env);

        return $this->renderer->renderFragment('flags/_flag_row.html.twig', [
            'flag' => $updatedView,
            'env' => $env,
            'prefix' => '/admin/flags',
        ]);
    }

    #[Route('/admin/flags/fragment/{name}/rollout', name: 'vortos.admin.flags.fragment.rollout', methods: ['POST'])]
    public function rollout(Request $request, string $name): Response
    {
        $this->authz->requirePermission('flags.write');
        $this->rateLimit->checkManagement($this->currentUser->get()->id());

        $env = $request->request->get('env', 'production');
        $this->scopeContext->withEnvironment($env);

        if ($this->changeRequestInterceptor->isProtected($name, $env)) {
            throw new ForbiddenException('This flag/environment requires a change request.');
        }

        $percentage = max(0, min(100, (int) $request->request->get('percentage', '0')));

        $flag = $this->storage->findByName($name);
        if ($flag === null) {
            throw new NotFoundException("Flag '{$name}' not found.");
        }

        $actorId = $this->currentUser->get()->id();

        $rules = [FlagRule::fromArray(['type' => FlagRule::TYPE_PERCENTAGE, 'percentage' => $percentage])];
        $this->writeService->enable($name, $actorId, "Rollout set to {$percentage}% via admin UI", $rules);

        return $this->renderer->renderFragment('flags/_rollout_slider.html.twig', [
            'flag_name' => $name,
            'percentage' => $percentage,
            'env' => $env,
            'prefix' => '/admin/flags',
        ]);
    }

    #[Route('/admin/flags/fragment/{name}/rules', name: 'vortos.admin.flags.fragment.rules', methods: ['PUT'])]
    public function updateRules(Request $request, string $name): Response
    {
        $this->authz->requirePermission('flags.write');
        $this->rateLimit->checkManagement($this->currentUser->get()->id());

        $env = $request->request->get('env', 'production');
        $this->scopeContext->withEnvironment($env);

        if ($this->changeRequestInterceptor->isProtected($name, $env)) {
            throw new ForbiddenException('This flag/environment requires a change request.');
        }

        $flag = $this->storage->findByName($name);
        if ($flag === null) {
            throw new NotFoundException("Flag '{$name}' not found.");
        }

        $rulesData = json_decode($request->getContent(), true);
        if (!is_array($rulesData)) {
            return new Response('Invalid rules JSON', 400);
        }

        $rules = array_map(static fn(array $r) => FlagRule::fromArray($r), $rulesData);

        $actorId = $this->currentUser->get()->id();
        $this->writeService->changeRules($name, $rules, $actorId, 'Rules updated via admin UI');

        $updatedFlag = $this->storage->findByName($name);
        $rulesJson = json_encode(
            array_map(static fn($r) => $r->toArray(), $updatedFlag->rules),
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT,
        );

        return $this->renderer->renderFragment('flags/_rules_saved.html.twig', [
            'flag_name' => $name,
            'rules_json' => $rulesJson,
        ]);
    }

    #[Route('/admin/flags/fragment/{name}/variants', name: 'vortos.admin.flags.fragment.variants', methods: ['PUT'])]
    public function updateVariants(Request $request, string $name): Response
    {
        $this->authz->requirePermission('flags.write');
        $this->rateLimit->checkManagement($this->currentUser->get()->id());

        $env = $request->request->get('env', 'production');
        $this->scopeContext->withEnvironment($env);

        $flag = $this->storage->findByName($name);
        if ($flag === null) {
            throw new NotFoundException("Flag '{$name}' not found.");
        }

        $variantsData = json_decode($request->getContent(), true);
        if (!is_array($variantsData)) {
            return new Response('Invalid variants JSON', 400);
        }

        $actorId = $this->currentUser->get()->id();
        $this->writeService->changeVariants($name, $variantsData, $actorId, 'Variants updated via admin UI');

        return $this->renderer->renderFragment('flags/_variants_saved.html.twig', [
            'flag_name' => $name,
        ]);
    }

    #[Route('/admin/flags/fragment/{name}/schedule', name: 'vortos.admin.flags.fragment.schedule', methods: ['POST'])]
    public function updateSchedule(Request $request, string $name): Response
    {
        $this->authz->requirePermission('flags.write');
        $this->rateLimit->checkManagement($this->currentUser->get()->id());

        $env = $request->request->get('env', 'production');
        $this->scopeContext->withEnvironment($env);

        $flag = $this->storage->findByName($name);
        if ($flag === null) {
            throw new NotFoundException("Flag '{$name}' not found.");
        }

        $scheduleData = json_decode($request->getContent(), true);
        $schedule = null;
        if (is_array($scheduleData) && !empty($scheduleData)) {
            $schedule = RolloutSchedule::fromArray($scheduleData);
        }

        $actorId = $this->currentUser->get()->id();
        $this->writeService->schedule($name, $schedule, $actorId, 'Schedule updated via admin UI');

        return $this->renderer->renderFragment('flags/_schedule_saved.html.twig', [
            'flag_name' => $name,
        ]);
    }

    #[Route('/admin/flags/fragment/create-form', name: 'vortos.admin.flags.fragment.create_form', methods: ['GET'])]
    public function createForm(Request $request): Response
    {
        $this->authz->requirePermission('flags.write');

        $env = $request->query->get('env', FlagScopeContext::ENV_PRODUCTION);

        return $this->renderer->renderFragment('flags/_create_form.html.twig', [
            'env'    => $env,
            'prefix' => '/admin/flags',
        ]);
    }

    #[Route('/admin/flags/fragment/create', name: 'vortos.admin.flags.fragment.create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $this->authz->requirePermission('flags.write');
        $this->rateLimit->checkManagement($this->currentUser->get()->id());

        $env     = (string) ($request->request->get('env') ?? FlagScopeContext::ENV_PRODUCTION);
        $project = (string) ($request->request->get('project') ?? ProjectContext::DEFAULT_PROJECT);
        $this->scopeContext->withEnvironment($env);
        $this->projectContext->withProject($project);

        $name = trim((string) ($request->request->get('name') ?? ''));
        if ($name === '' || !preg_match('/^[a-z0-9][a-z0-9\-]*$/', $name)) {
            return $this->renderer->renderFragment('flags/_create_form.html.twig', [
                'env'    => $env,
                'prefix' => '/admin/flags',
                'error'  => 'Flag name must be lowercase letters, numbers, and hyphens.',
            ]);
        }

        if ($this->storage->findByName($name) !== null) {
            return $this->renderer->renderFragment('flags/_create_form.html.twig', [
                'env'    => $env,
                'prefix' => '/admin/flags',
                'error'  => "Flag \"{$name}\" already exists.",
            ]);
        }

        $kindValue  = (string) ($request->request->get('kind') ?? 'release');
        $valueType  = (string) ($request->request->get('value_type') ?? 'bool');
        $description = (string) ($request->request->get('description') ?? '');
        $now = new \DateTimeImmutable();

        $flag = new FeatureFlag(
            id:          (string) Uuid::v4(),
            name:        $name,
            description: $description,
            enabled:     false,
            rules:       [],
            variants:    null,
            createdAt:   $now,
            updatedAt:   $now,
            valueType:   FlagValueType::from($valueType),
            kind:        FlagKind::from($kindValue),
        );

        $this->writeService->create($flag, $this->currentUser->get()->id(), 'Created via admin UI');

        $response = new Response('', 204);
        $response->headers->set('HX-Redirect', '/admin/flags/detail/' . rawurlencode($name) . '?env=' . rawurlencode($env));

        return $response;
    }

    #[Route('/admin/flags/fragment/{name}', name: 'vortos.admin.flags.fragment.delete', methods: ['DELETE'])]
    public function delete(Request $request, string $name): Response
    {
        $this->authz->requirePermission('flags.write');
        $this->rateLimit->checkManagement($this->currentUser->get()->id());

        $env = $request->request->get('env', FlagScopeContext::ENV_PRODUCTION);
        $this->scopeContext->withEnvironment($env);

        if ($this->storage->findByName($name) === null) {
            throw new NotFoundException("Flag '{$name}' not found.");
        }

        $this->writeService->archiveAndDelete($name, $this->currentUser->get()->id(), 'Deleted via admin UI');

        $response = new Response('', 204);
        $response->headers->set('HX-Redirect', '/admin/flags');

        return $response;
    }
}
