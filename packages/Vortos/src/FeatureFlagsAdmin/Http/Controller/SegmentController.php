<?php

declare(strict_types=1);

namespace Vortos\FeatureFlagsAdmin\Http\Controller;

use Symfony\Component\Routing\Attribute\Route;
use Vortos\Auth\Identity\CurrentUserProvider;
use Vortos\FeatureFlags\Authz\Management\ManagementAuthzGateInterface;
use Vortos\FeatureFlags\Http\RateLimit\FlagRateLimitService;
use Vortos\FeatureFlags\Storage\SegmentStorageInterface;
use Vortos\FeatureFlagsAdmin\Rendering\TwigRenderer;
use Vortos\Http\Attribute\AsController;
use Vortos\Http\Exception\NotFoundException;
use Vortos\Http\Request;
use Vortos\Http\Response;

#[AsController]
final class SegmentController
{
    public function __construct(
        private readonly TwigRenderer $renderer,
        private readonly SegmentStorageInterface $segmentStorage,
        private readonly ManagementAuthzGateInterface $authz,
        private readonly CurrentUserProvider $currentUser,
        private readonly FlagRateLimitService $rateLimit,
    ) {}

    #[Route('/admin/flags/segments', name: 'vortos.admin.flags.segments', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->authz->requirePermission('flags.read');
        $this->rateLimit->checkManagement($this->currentUser->get()->id());

        $segments = $this->segmentStorage->findAll();

        return $this->renderer->render('segments/index.html.twig', [
            'segments' => $segments,
            'active_nav' => 'segments',
            'prefix' => '/admin/flags',
        ]);
    }

    #[Route('/admin/flags/segments/{id}', name: 'vortos.admin.flags.segments.detail', methods: ['GET'])]
    public function detail(Request $request, string $id): Response
    {
        $this->authz->requirePermission('flags.read');
        $this->rateLimit->checkManagement($this->currentUser->get()->id());

        $segment = $this->segmentStorage->findByName($id);
        if ($segment === null) {
            throw new NotFoundException("Segment '{$id}' not found.");
        }

        $rulesJson = json_encode(
            array_map(static fn($r) => $r->toArray(), $segment->rules),
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT,
        );

        return $this->renderer->render('segments/detail.html.twig', [
            'segment' => $segment,
            'rules_json' => $rulesJson,
            'active_nav' => 'segments',
            'prefix' => '/admin/flags',
        ]);
    }
}
