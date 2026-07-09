<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Cutover\Drift;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Cutover\Drift\EdgeDriftDetector;
use Vortos\Deploy\Cutover\Edge\BootConfigReaderInterface;
use Vortos\Deploy\Cutover\Edge\LiveConfigReaderInterface;
use Vortos\Deploy\Cutover\Edge\MergeOutcome;
use Vortos\Deploy\Cutover\State\EdgeState;
use Vortos\Deploy\Cutover\State\EdgeStateStoreInterface;
use Vortos\Deploy\Target\ActiveColor;

final class EdgeDriftDetectorTest extends TestCase
{
    /** @param array<string,mixed> $live */
    private function detector(?EdgeState $state, array $live, ?string $bootJson): EdgeDriftDetector
    {
        $store = new class($state) implements EdgeStateStoreInterface {
            public function __construct(private readonly ?EdgeState $state) {}

            public function load(string $env): ?EdgeState
            {
                return $this->state;
            }

            public function save(EdgeState $state): EdgeState
            {
                return $state;
            }
        };

        $reader = new class($live) implements LiveConfigReaderInterface {
            public function __construct(private readonly array $live) {}

            public function currentConfig(): array
            {
                return $this->live;
            }
        };

        $boot = $bootJson === null ? null : new class($bootJson) implements BootConfigReaderInterface {
            public function __construct(private readonly string $json) {}

            public function read(): ?string
            {
                return $this->json;
            }
        };

        return new EdgeDriftDetector($store, $reader, $boot);
    }

    private function state(?string $configHash): EdgeState
    {
        return new EdgeState(
            env: 'production',
            activeColor: ActiveColor::Green,
            upstreamHost: 'app-green',
            upstreamPort: 8080,
            configHash: $configHash,
        );
    }

    /** @return array<string,mixed> */
    private function liveWithDial(string $dial): array
    {
        return ['apps' => ['http' => ['servers' => ['app' => ['routes' => [[
            'handle' => [['handler' => 'reverse_proxy', 'upstreams' => [['dial' => $dial]]]],
        ]]]]]]];
    }

    public function testSkipsWhenNoState(): void
    {
        $report = $this->detector(null, [], null)->detect('production');
        self::assertFalse($report->hasState);
        self::assertTrue($report->inSync);
    }

    public function testInSyncWhenLiveAndBootMatch(): void
    {
        $live = $this->liveWithDial('app-green:8080');
        $hash = hash('sha256', MergeOutcome::canonicalize($live));

        $report = $this->detector($this->state($hash), $live, json_encode($live, \JSON_THROW_ON_ERROR))
            ->detect('production');

        self::assertTrue($report->inSync, $report->summary());
    }

    public function testDriftWhenLiveUpstreamWrong(): void
    {
        $report = $this->detector($this->state(null), $this->liveWithDial('app-blue:8080'), null)
            ->detect('production');

        self::assertFalse($report->inSync);
        self::assertStringContainsString('live upstream does not match', $report->summary());
    }

    public function testDriftWhenBootFileStale(): void
    {
        $live = $this->liveWithDial('app-green:8080');
        // Recorded hash is of a DIFFERENT config than the boot file on disk.
        $report = $this->detector($this->state('deadbeef'), $live, json_encode($live, \JSON_THROW_ON_ERROR))
            ->detect('production');

        self::assertFalse($report->inSync);
        self::assertStringContainsString('boot file does not match', $report->summary());
    }

    public function testDriftWhenAdminUnreachable(): void
    {
        $store = new class($this->state(null)) implements EdgeStateStoreInterface {
            public function __construct(private readonly EdgeState $state) {}

            public function load(string $env): ?EdgeState
            {
                return $this->state;
            }

            public function save(EdgeState $state): EdgeState
            {
                return $state;
            }
        };

        $throwing = new class implements LiveConfigReaderInterface {
            public function currentConfig(): array
            {
                throw new \RuntimeException('connection refused');
            }
        };

        $report = (new EdgeDriftDetector($store, $throwing))->detect('production');

        self::assertFalse($report->inSync);
        self::assertStringContainsString('unreachable', $report->summary());
    }
}
