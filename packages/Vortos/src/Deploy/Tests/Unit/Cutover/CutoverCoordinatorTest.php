<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Cutover;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Compose\ColorEndpoint;
use Vortos\Deploy\Cutover\CutoverCoordinator;
use Vortos\Deploy\Cutover\DesiredRoute;
use Vortos\Deploy\Cutover\NullCutoverEventRecorder;
use Vortos\Deploy\Exception\CutoverRevertedException;
use Vortos\Deploy\State\CurrentRelease;
use Vortos\Deploy\Target\ActiveColor;
use Vortos\Deploy\Tests\Fixtures\FakeDeployStateStore;
use Vortos\Deploy\Tests\Fixtures\FakeEdgeRouter;

final class CutoverCoordinatorTest extends TestCase
{
    private FakeEdgeRouter $router;
    private FakeDeployStateStore $store;
    private CutoverCoordinator $coordinator;

    protected function setUp(): void
    {
        $this->router = new FakeEdgeRouter();
        $this->store = new FakeDeployStateStore();
        $this->coordinator = new CutoverCoordinator(
            $this->router,
            $this->store,
            new NullCutoverEventRecorder(),
        );
    }

    public function test_happy_path_records_current_release_with_incremented_generation(): void
    {
        $desired = $this->makeDesired(ActiveColor::Blue);

        $result = $this->coordinator->cutover(
            $desired, 'sha256:img1', 'build-1', 'sha256:plan1',
            new ColorEndpoint('app-green', 8082),
        );

        $this->assertTrue($result->succeeded);
        $this->assertTrue($result->verifiedLiveUpstream);

        $release = $this->store->currentRelease('production');
        $this->assertNotNull($release);
        $this->assertSame(ActiveColor::Blue, $release->activeColor);
        $this->assertSame(1, $release->generation);
    }

    public function test_second_cutover_increments_generation(): void
    {
        $this->coordinator->cutover(
            $this->makeDesired(ActiveColor::Blue), 'sha256:img1', 'build-1', 'sha256:plan1',
            new ColorEndpoint('app-green', 8082),
        );

        $this->coordinator->cutover(
            $this->makeDesired(ActiveColor::Green), 'sha256:img2', 'build-2', 'sha256:plan2',
            new ColorEndpoint('app-blue', 8081),
        );

        $release = $this->store->currentRelease('production');
        $this->assertNotNull($release);
        $this->assertSame(ActiveColor::Green, $release->activeColor);
        $this->assertSame(2, $release->generation);
    }

    public function test_verify_mismatch_reverts_to_previous_and_throws(): void
    {
        $this->coordinator->cutover(
            $this->makeDesired(ActiveColor::Blue), 'sha256:img1', 'build-1', 'sha256:plan1',
            new ColorEndpoint('app-green', 8082),
        );

        $this->router->setFailVerify(true);

        $this->expectException(CutoverRevertedException::class);
        $this->expectExceptionMessage('reverted to blue');

        $this->coordinator->cutover(
            $this->makeDesired(ActiveColor::Green), 'sha256:img2', 'build-2', 'sha256:plan2',
            new ColorEndpoint('app-blue', 8081),
        );
    }

    public function test_verify_mismatch_does_not_record_new_release(): void
    {
        $this->coordinator->cutover(
            $this->makeDesired(ActiveColor::Blue), 'sha256:img1', 'build-1', 'sha256:plan1',
            new ColorEndpoint('app-green', 8082),
        );

        $this->router->setFailVerify(true);

        try {
            $this->coordinator->cutover(
                $this->makeDesired(ActiveColor::Green), 'sha256:img2', 'build-2', 'sha256:plan2',
                new ColorEndpoint('app-blue', 8081),
            );
        } catch (CutoverRevertedException) {
        }

        $release = $this->store->currentRelease('production');
        $this->assertSame(1, $release->generation);
        $this->assertSame(ActiveColor::Blue, $release->activeColor);
    }

    public function test_first_deploy_failure_throws_no_previous_color(): void
    {
        $this->router->setFailCutover(true);

        $this->expectException(CutoverRevertedException::class);
        $this->expectExceptionMessage('no previous color');

        $this->coordinator->cutover(
            $this->makeDesired(ActiveColor::Blue), 'sha256:img1', 'build-1', 'sha256:plan1',
            new ColorEndpoint('app-green', 8082),
        );
    }

    public function test_cutover_failure_with_previous_reverts_to_previous(): void
    {
        $this->coordinator->cutover(
            $this->makeDesired(ActiveColor::Blue), 'sha256:img1', 'build-1', 'sha256:plan1',
            new ColorEndpoint('app-green', 8082),
        );

        $this->router->setFailCutover(true);

        try {
            $this->coordinator->cutover(
                $this->makeDesired(ActiveColor::Green), 'sha256:img2', 'build-2', 'sha256:plan2',
                new ColorEndpoint('app-blue', 8081),
            );
            $this->fail('Expected CutoverRevertedException');
        } catch (CutoverRevertedException) {
            $history = $this->router->cutoverHistory();
            $lastCutover = end($history);
            $this->assertSame(ActiveColor::Blue, $lastCutover->activeColor);
        }
    }

    private function makeDesired(ActiveColor $color): DesiredRoute
    {
        $host = $color === ActiveColor::Blue ? 'app-blue' : 'app-green';
        $port = $color === ActiveColor::Blue ? 8081 : 8082;

        return new DesiredRoute(
            env: 'production',
            activeColor: $color,
            upstream: new ColorEndpoint($host, $port),
            drainDeadlineSeconds: 5,
        );
    }
}
