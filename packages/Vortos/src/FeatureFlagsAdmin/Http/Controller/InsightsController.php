<?php

declare(strict_types=1);

namespace Vortos\FeatureFlagsAdmin\Http\Controller;

use Symfony\Component\Routing\Attribute\Route;
use Vortos\Auth\Identity\CurrentUserProvider;
use Vortos\FeatureFlags\Authz\Management\ManagementAuthzGateInterface;
use Vortos\FeatureFlags\Http\RateLimit\FlagRateLimitService;
use Vortos\FeatureFlagsAdmin\Rendering\TwigRenderer;
use Vortos\Http\Attribute\AsController;
use Vortos\Http\Request;
use Vortos\Http\Response;

#[AsController]
final class InsightsController
{
    private const ALLOWED_METRIC_LABELS = [
        'flag',
        'result',
        'variant',
        'operation',
    ];

    public function __construct(
        private readonly TwigRenderer $renderer,
        private readonly ManagementAuthzGateInterface $authz,
        private readonly CurrentUserProvider $currentUser,
        private readonly FlagRateLimitService $rateLimit,
    ) {}

    #[Route('/admin/flags/insights', name: 'vortos.admin.flags.insights', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->authz->requirePermission('flags.insights.read');
        $this->rateLimit->checkManagement($this->currentUser->get()->id());

        $flagName = $request->query->get('flag', '');

        return $this->renderer->render('insights/index.html.twig', [
            'flag_name' => $flagName,
            'allowed_labels' => self::ALLOWED_METRIC_LABELS,
            'active_nav' => 'insights',
            'prefix' => '/admin/flags',
        ]);
    }

    #[Route('/admin/flags/insights/data', name: 'vortos.admin.flags.insights.data', methods: ['GET'])]
    public function data(Request $request): Response
    {
        $this->authz->requirePermission('flags.insights.read');
        $this->rateLimit->checkManagement($this->currentUser->get()->id());

        $flagName = $request->query->get('flag', '');

        $data = [
            'labels' => self::ALLOWED_METRIC_LABELS,
            'flag' => $flagName,
            'series' => [],
        ];

        return new \Vortos\Http\JsonResponse($data);
    }
}
