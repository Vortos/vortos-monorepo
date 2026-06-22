<?php

declare(strict_types=1);

namespace Vortos\FeatureFlagsAdmin\Http\Controller;

use Symfony\Component\Routing\Attribute\Route;
use Vortos\Auth\Identity\CurrentUserProvider;
use Vortos\FeatureFlags\Authz\Management\ManagementAuthzGateInterface;
use Vortos\FeatureFlags\Http\RateLimit\FlagRateLimitService;
use Vortos\FeatureFlags\ReadModel\FlagStateViewRepositoryInterface;
use Vortos\FeatureFlagsAdmin\Rendering\TwigRenderer;
use Vortos\Http\Attribute\AsController;
use Vortos\Http\Request;
use Vortos\Http\Response;

#[AsController]
final class EnvCompareController
{
    public function __construct(
        private readonly TwigRenderer $renderer,
        private readonly FlagStateViewRepositoryInterface $stateView,
        private readonly ManagementAuthzGateInterface $authz,
        private readonly CurrentUserProvider $currentUser,
        private readonly FlagRateLimitService $rateLimit,
    ) {}

    #[Route('/admin/flags/env-compare', name: 'vortos.admin.flags.envcompare', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->authz->requirePermission('flags.read');
        $this->rateLimit->checkManagement($this->currentUser->get()->id());

        $envA = $request->query->get('env_a', 'staging');
        $envB = $request->query->get('env_b', 'production');

        $flagsA = $this->stateView->all($envA, 1000);
        $flagsB = $this->stateView->all($envB, 1000);

        $indexA = [];
        foreach ($flagsA as $f) {
            $indexA[$f->flagName] = $f;
        }
        $indexB = [];
        foreach ($flagsB as $f) {
            $indexB[$f->flagName] = $f;
        }

        $allNames = array_unique(array_merge(array_keys($indexA), array_keys($indexB)));
        sort($allNames);

        $comparisons = [];
        foreach ($allNames as $name) {
            $a = $indexA[$name] ?? null;
            $b = $indexB[$name] ?? null;

            $status = 'same';
            if ($a === null) {
                $status = 'only_b';
            } elseif ($b === null) {
                $status = 'only_a';
            } elseif ($a->enabled !== $b->enabled || $a->ruleCount !== $b->ruleCount || $a->variants !== $b->variants) {
                $status = 'different';
            }

            $comparisons[] = [
                'name' => $name,
                'status' => $status,
                'a' => $a,
                'b' => $b,
            ];
        }

        if ($request->headers->get('HX-Request') === 'true') {
            return $this->renderer->renderFragment('env_compare/_compare_table.html.twig', [
                'comparisons' => $comparisons,
                'env_a' => $envA,
                'env_b' => $envB,
                'prefix' => '/admin/flags',
            ]);
        }

        return $this->renderer->render('env_compare/index.html.twig', [
            'comparisons' => $comparisons,
            'env_a' => $envA,
            'env_b' => $envB,
            'environments' => ['production', 'staging', 'development', 'test'],
            'active_nav' => 'env_compare',
            'prefix' => '/admin/flags',
        ]);
    }
}
