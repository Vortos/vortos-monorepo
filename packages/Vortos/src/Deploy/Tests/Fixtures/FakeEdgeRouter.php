<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Fixtures;

use Vortos\Deploy\Cutover\CutoverResult;
use Vortos\Deploy\Cutover\DesiredRoute;
use Vortos\Deploy\Cutover\EdgeRouterCapability;
use Vortos\Deploy\Cutover\EdgeRouterInterface;
use Vortos\Deploy\Cutover\LiveRoute;
use Vortos\Deploy\Cutover\ReconcileResult;
use Vortos\Deploy\Exception\CutoverFailedException;
use Vortos\OpsKit\Attribute\AsDriver;
use Vortos\OpsKit\Driver\Capability\CapabilityDescriptor;
use Vortos\OpsKit\Driver\Exception\UnsupportedCapabilityException;

#[AsDriver('fake')]
final class FakeEdgeRouter implements EdgeRouterInterface
{
    private ?LiveRoute $liveRouteState = null;
    private bool $shouldFailCutover = false;
    private int $failCutoverCountdown = -1;
    private bool $shouldFailVerify = false;
    private int $failVerifyCountdown = -1;
    private int $activeRequests = 0;

    /** @var list<DesiredRoute> */
    private array $cutoverHistory = [];

    public function capabilities(): CapabilityDescriptor
    {
        return CapabilityDescriptor::create([
            EdgeRouterCapability::ConnectionDraining->value => true,
            EdgeRouterCapability::AtomicSwap->value => true,
            EdgeRouterCapability::VerifiedCutover->value => true,
            EdgeRouterCapability::WeightedUpstreams->value => false,
            EdgeRouterCapability::DurableState->value => true,
        ]);
    }

    public function cutover(DesiredRoute $desired): CutoverResult
    {
        if ($desired->weight !== 100) {
            throw UnsupportedCapabilityException::for(
                EdgeRouterCapability::WeightedUpstreams->value,
                'fake',
            );
        }

        $this->cutoverHistory[] = $desired;

        if ($this->shouldFailCutover) {
            if ($this->failCutoverCountdown > 0) {
                $this->failCutoverCountdown--;
                if ($this->failCutoverCountdown === 0) {
                    $this->shouldFailCutover = false;
                    $this->failCutoverCountdown = -1;
                }
            }
            throw CutoverFailedException::reloadFailed('fake: cutover failed');
        }

        $drained = $this->activeRequests;
        $this->activeRequests = 0;

        $this->liveRouteState = new LiveRoute(
            activeColor: $desired->activeColor,
            upstreamHost: $desired->upstream->host,
            upstreamPort: $desired->upstream->port,
            weight: $desired->weight,
        );

        $shouldFailThisTime = $this->shouldFailVerify;
        if ($this->failVerifyCountdown > 0) {
            $this->failVerifyCountdown--;
            if ($this->failVerifyCountdown === 0) {
                $this->shouldFailVerify = false;
                $this->failVerifyCountdown = -1;
            }
        }

        if ($shouldFailThisTime) {
            throw CutoverFailedException::verifyMismatch(
                $desired->upstream->host . ':' . $desired->upstream->port,
                'fake: verify failed',
            );
        }

        return new CutoverResult(
            succeeded: true,
            reverted: false,
            drainedConnections: $drained,
            forciblyClosed: 0,
            durationMs: 10,
            verifiedLiveUpstream: true,
            detail: 'fake cutover ok',
        );
    }

    public function liveRoute(): ?LiveRoute
    {
        return $this->liveRouteState;
    }

    public function reconcile(DesiredRoute $desired): ReconcileResult
    {
        $live = $this->liveRoute();

        if ($live !== null && $live->equalsDesired($desired)) {
            return new ReconcileResult(inSync: true, detail: 'fake: in-sync');
        }

        $this->cutover($desired);

        return new ReconcileResult(
            inSync: false,
            drifted: true,
            corrected: true,
            detail: 'fake: reconciled',
        );
    }

    public function setFailCutover(bool $fail): void
    {
        $this->shouldFailCutover = $fail;
        $this->failCutoverCountdown = $fail ? 1 : -1;
    }

    public function setFailVerify(bool $fail): void
    {
        $this->shouldFailVerify = $fail;
        $this->failVerifyCountdown = $fail ? 1 : -1;
    }

    public function setActiveRequests(int $count): void
    {
        $this->activeRequests = $count;
    }

    public function setLiveRoute(?LiveRoute $route): void
    {
        $this->liveRouteState = $route;
    }

    /** @return list<DesiredRoute> */
    public function cutoverHistory(): array
    {
        return $this->cutoverHistory;
    }

    public function clearHistory(): void
    {
        $this->cutoverHistory = [];
    }
}
