<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Http\Management;

use Symfony\Component\Routing\Attribute\Route;
use Vortos\Auth\Identity\CurrentUserProvider;
use Vortos\FeatureFlags\Authz\Management\ManagementAuthzGateInterface;
use Vortos\FeatureFlags\FeatureFlag;
use Vortos\FeatureFlags\Http\RateLimit\FlagRateLimitService;
use Vortos\FeatureFlags\Storage\FlagStorageInterface;
use Vortos\Http\Attribute\AsController;
use Vortos\Http\JsonResponse;

/**
 * Portfolio insights: aggregate counts across the flag estate — by kind, lifecycle and
 * status, plus stale/expiring flags and how many carry targeting/variants. Feeds the
 * console's insights dashboard without needing a metrics backend.
 */
#[AsController]
final class FlagInsightsController
{
    public function __construct(
        private readonly FlagStorageInterface $storage,
        private readonly ManagementAuthzGateInterface $authz,
        private readonly CurrentUserProvider $currentUser,
        private readonly FlagRateLimitService $rateLimit,
        private readonly ManagementResponseFactory $response,
    ) {}

    #[Route('/api/management/v1/insights', name: 'vortos.management.insights', methods: ['GET'])]
    public function index(): JsonResponse
    {
        $this->authz->requirePermission('flags.read.any');
        $this->rateLimit->checkManagement($this->currentUser->get()->id());

        $flags = $this->storage->findAll();
        $now   = new \DateTimeImmutable();

        $byKind = $byLifecycle = [];
        $enabled = $disabled = $targeted = $variant = $stale = 0;

        foreach ($flags as $flag) {
            $byKind[$flag->kind->value]           = ($byKind[$flag->kind->value] ?? 0) + 1;
            $byLifecycle[$flag->lifecycle->value] = ($byLifecycle[$flag->lifecycle->value] ?? 0) + 1;

            if ($flag->lifecycle->isLive()) {
                $flag->enabled ? $enabled++ : $disabled++;
            }
            if ($flag->rules !== []) {
                $targeted++;
            }
            if ($flag->variants !== null) {
                $variant++;
            }
            if ($this->isStale($flag, $now)) {
                $stale++;
            }
        }

        return $this->response->ok([
            'total'        => count($flags),
            'enabled'      => $enabled,
            'disabled'     => $disabled,
            'targeted'     => $targeted,
            'multivariate' => $variant,
            'stale'        => $stale,
            'byKind'       => $byKind,
            'byLifecycle'  => $byLifecycle,
        ]);
    }

    /** Expired, or a long-lived active flag that has not changed in 90 days. */
    private function isStale(FeatureFlag $flag, \DateTimeImmutable $now): bool
    {
        if ($flag->isExpired($now)) {
            return true;
        }
        if (!$flag->lifecycle->isLive()) {
            return false;
        }
        return $flag->updatedAt < $now->modify('-90 days');
    }
}
