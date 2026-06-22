<?php

declare(strict_types=1);

namespace Vortos\FeatureFlagsAdmin\Http\Controller;

use Symfony\Component\Routing\Attribute\Route;
use Vortos\Auth\Identity\CurrentUserProvider;
use Vortos\FeatureFlags\Application\FlagWriteService;
use Vortos\FeatureFlags\Authz\Management\ManagementAuthzGateInterface;
use Vortos\FeatureFlags\FlagKind;
use Vortos\FeatureFlags\FlagScopeContext;
use Vortos\FeatureFlags\Guardrail\Storage\GuardrailPolicyStorageInterface;
use Vortos\FeatureFlags\Http\RateLimit\FlagRateLimitService;
use Vortos\FeatureFlags\Storage\FlagStorageInterface;
use Vortos\FeatureFlagsAdmin\Rendering\TwigRenderer;
use Vortos\Http\Attribute\AsController;
use Vortos\Http\Exception\ForbiddenException;
use Vortos\Http\Exception\NotFoundException;
use Vortos\Http\Request;
use Vortos\Http\Response;

#[AsController]
final class KillSwitchController
{
    public function __construct(
        private readonly TwigRenderer $renderer,
        private readonly FlagStorageInterface $storage,
        private readonly FlagWriteService $writeService,
        private readonly ManagementAuthzGateInterface $authz,
        private readonly CurrentUserProvider $currentUser,
        private readonly FlagRateLimitService $rateLimit,
        private readonly FlagScopeContext $scopeContext,
        private readonly ?GuardrailPolicyStorageInterface $guardrailStorage = null,
    ) {}

    #[Route('/admin/flags/kill-switch', name: 'vortos.admin.flags.killswitch', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->authz->requirePermission('flags.killswitch');
        $this->rateLimit->checkManagement($this->currentUser->get()->id());

        $env = $request->query->get('env', 'production');

        $allFlags = $this->storage->findAll();
        $opsFlags = array_filter($allFlags, static fn($f) => $f->kind === FlagKind::Ops);
        $opsFlags = array_values($opsFlags);

        $guardrailStates = [];
        if ($this->guardrailStorage !== null) {
            foreach ($opsFlags as $flag) {
                $policies = $this->guardrailStorage->findEnabled('default', $env);
                if (!empty($policies)) {
                    $guardrailStates[$flag->name] = $policies;
                }
            }
        }

        return $this->renderer->render('kill_switch/index.html.twig', [
            'flags' => $opsFlags,
            'guardrail_states' => $guardrailStates,
            'env' => $env,
            'active_nav' => 'kill_switch',
            'prefix' => '/admin/flags',
        ]);
    }

    #[Route('/admin/flags/kill-switch/{name}/toggle', name: 'vortos.admin.flags.killswitch.toggle', methods: ['POST'])]
    public function toggle(Request $request, string $name): Response
    {
        $this->authz->requirePermission('flags.killswitch');
        $this->rateLimit->checkManagement($this->currentUser->get()->id());

        $env = $request->request->get('env', 'production');
        $this->scopeContext->withEnvironment($env);

        $flag = $this->storage->findByName($name);
        if ($flag === null) {
            throw new NotFoundException("Flag '{$name}' not found.");
        }

        if ($flag->kind !== FlagKind::Ops) {
            throw new ForbiddenException('Kill switch is only available for ops/kill-switch flags.');
        }

        $actorId = $this->currentUser->get()->id();
        $reason = "Kill switch toggled via admin UI";

        if ($flag->enabled) {
            $this->writeService->disable($name, $actorId, $reason);
        } else {
            $this->writeService->enable($name, $actorId, $reason);
        }

        if ($request->headers->get('HX-Request') === 'true') {
            $updatedFlag = $this->storage->findByName($name);

            return $this->renderer->renderFragment('kill_switch/_flag_row.html.twig', [
                'flag' => $updatedFlag,
                'guardrails' => $this->guardrailStorage?->findEnabled('default', $env) ?? [],
                'prefix' => '/admin/flags',
            ]);
        }

        return new Response('', 302, ['Location' => '/admin/flags/kill-switch']);
    }
}
