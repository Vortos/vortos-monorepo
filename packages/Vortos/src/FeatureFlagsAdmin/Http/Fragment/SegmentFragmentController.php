<?php

declare(strict_types=1);

namespace Vortos\FeatureFlagsAdmin\Http\Fragment;

use Symfony\Component\Routing\Attribute\Route;
use Vortos\Auth\Identity\CurrentUserProvider;
use Vortos\FeatureFlags\Authz\Management\ManagementAuthzGateInterface;
use Vortos\FeatureFlags\FlagRule;
use Vortos\FeatureFlags\Http\RateLimit\FlagRateLimitService;
use Vortos\FeatureFlags\Segment;
use Vortos\FeatureFlags\Storage\SegmentStorageInterface;
use Vortos\FeatureFlagsAdmin\Rendering\TwigRenderer;
use Vortos\Http\Attribute\AsController;
use Vortos\Http\Exception\NotFoundException;
use Vortos\Http\Request;
use Vortos\Http\Response;

#[AsController]
final class SegmentFragmentController
{
    public function __construct(
        private readonly TwigRenderer $renderer,
        private readonly SegmentStorageInterface $segmentStorage,
        private readonly ManagementAuthzGateInterface $authz,
        private readonly CurrentUserProvider $currentUser,
        private readonly FlagRateLimitService $rateLimit,
    ) {}

    #[Route('/admin/flags/fragment/segments/{id}/rules', name: 'vortos.admin.flags.fragment.segments.rules', methods: ['PUT'])]
    public function updateRules(Request $request, string $id): Response
    {
        $this->authz->requirePermission('flags.write');
        $this->rateLimit->checkManagement($this->currentUser->get()->id());

        $segment = $this->segmentStorage->findByName($id);
        if ($segment === null) {
            throw new NotFoundException("Segment '{$id}' not found.");
        }

        $rulesData = json_decode($request->getContent(), true);
        if (!is_array($rulesData)) {
            return new Response('Invalid rules JSON', 400);
        }

        $rules = array_map(static fn(array $r) => FlagRule::fromArray($r), $rulesData);

        $updated = new Segment(
            id: $segment->id,
            name: $segment->name,
            description: $segment->description,
            rules: $rules,
            projectId: $segment->projectId,
        );

        $this->segmentStorage->save($updated);

        return $this->renderer->renderFragment('segments/_rules_saved.html.twig', [
            'segment_id' => $id,
        ]);
    }
}
