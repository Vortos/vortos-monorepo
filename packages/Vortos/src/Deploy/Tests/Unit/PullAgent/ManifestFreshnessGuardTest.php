<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\PullAgent;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Exception\ManifestReplayException;
use Vortos\Deploy\Exception\StaleManifestException;
use Vortos\Deploy\PullAgent\DesiredStateManifest;
use Vortos\Deploy\PullAgent\FreshnessSnapshot;
use Vortos\Deploy\PullAgent\ManifestFreshnessGuard;

final class ManifestFreshnessGuardTest extends TestCase
{
    public function test_accepts_first_manifest(): void
    {
        $guard = new ManifestFreshnessGuard();
        $manifest = $this->makeManifest(version: 1);

        $guard->assertFresh($manifest, new \DateTimeImmutable());

        $this->addToAssertionCount(1);
    }

    public function test_accepts_newer_version(): void
    {
        $guard = new ManifestFreshnessGuard();

        $m1 = $this->makeManifest(version: 1, nonce: 'n1');
        $guard->assertFresh($m1, new \DateTimeImmutable());
        $guard->recordApplied($m1);

        $m2 = $this->makeManifest(version: 2, nonce: 'n2');
        $guard->assertFresh($m2, new \DateTimeImmutable());

        $this->addToAssertionCount(1);
    }

    public function test_rejects_rollback_version(): void
    {
        $guard = new ManifestFreshnessGuard();

        $m2 = $this->makeManifest(version: 2, nonce: 'n1');
        $guard->assertFresh($m2, new \DateTimeImmutable());
        $guard->recordApplied($m2);

        $m1 = $this->makeManifest(version: 1, nonce: 'n2');

        $this->expectException(StaleManifestException::class);
        $this->expectExceptionMessage('version 1 is not newer than last applied version 2');

        $guard->assertFresh($m1, new \DateTimeImmutable());
    }

    public function test_rejects_same_version_replay(): void
    {
        $guard = new ManifestFreshnessGuard();

        $m = $this->makeManifest(version: 1, nonce: 'same-nonce');
        $guard->assertFresh($m, new \DateTimeImmutable());
        $guard->recordApplied($m);

        $replay = $this->makeManifest(version: 2, nonce: 'same-nonce');

        $this->expectException(ManifestReplayException::class);
        $this->expectExceptionMessage('same-nonce');

        $guard->assertFresh($replay, new \DateTimeImmutable());
    }

    public function test_rejects_stale_issued_at(): void
    {
        $guard = new ManifestFreshnessGuard(freshnessWindowSeconds: 60);

        $staleTime = (new \DateTimeImmutable())->modify('-120 seconds');
        $manifest = new DesiredStateManifest(
            env: 'prod',
            releaseVersion: '1.0.0',
            imageDigest: 'sha256:' . str_repeat('a', 64),
            activeColor: 'blue',
            composeProjection: '{}',
            schemaFingerprint: 'fp',
            issuedAt: $staleTime,
            version: 1,
            nonce: 'fresh-nonce',
        );

        $this->expectException(StaleManifestException::class);
        $this->expectExceptionMessage('outside the freshness window');

        $guard->assertFresh($manifest, new \DateTimeImmutable());
    }

    public function test_rejects_future_issued_at_beyond_skew(): void
    {
        $guard = new ManifestFreshnessGuard(freshnessWindowSeconds: 60);

        $futureTime = (new \DateTimeImmutable())->modify('+120 seconds');
        $manifest = new DesiredStateManifest(
            env: 'prod',
            releaseVersion: '1.0.0',
            imageDigest: 'sha256:' . str_repeat('a', 64),
            activeColor: 'blue',
            composeProjection: '{}',
            schemaFingerprint: 'fp',
            issuedAt: $futureTime,
            version: 1,
            nonce: 'future-nonce',
        );

        $this->expectException(StaleManifestException::class);

        $guard->assertFresh($manifest, new \DateTimeImmutable());
    }

    public function test_record_applied_tracks_version(): void
    {
        $guard = new ManifestFreshnessGuard();

        $this->assertSame(0, $guard->lastAppliedVersion('prod'));

        $manifest = $this->makeManifest(version: 5);
        $guard->assertFresh($manifest, new \DateTimeImmutable());
        $guard->recordApplied($manifest);

        $this->assertSame(5, $guard->lastAppliedVersion('prod'));
    }

    public function test_load_state_restores_version_and_nonces(): void
    {
        $guard = new ManifestFreshnessGuard();
        $now = new \DateTimeImmutable();
        $guard->loadState(new FreshnessSnapshot('prod', 3, [
            'old-nonce-1' => $now,
            'old-nonce-2' => $now,
        ]));

        $this->assertSame(3, $guard->lastAppliedVersion('prod'));

        $m = $this->makeManifest(version: 4, nonce: 'old-nonce-1');

        $this->expectException(ManifestReplayException::class);

        $guard->assertFresh($m, new \DateTimeImmutable());
    }

    public function test_snapshot_round_trips_through_load_state(): void
    {
        $guard = new ManifestFreshnessGuard();
        $now = new \DateTimeImmutable();

        $m = $this->makeManifest(version: 7, nonce: 'n7', env: 'prod');
        $guard->assertFresh($m, $now);
        $guard->recordApplied($m);

        $snapshot = $guard->snapshot('prod', $now);
        $this->assertSame(7, $snapshot->lastAppliedVersion);
        $this->assertArrayHasKey('n7', $snapshot->seenNonces);

        $restored = new ManifestFreshnessGuard();
        $restored->loadState($snapshot);

        $this->assertSame(7, $restored->lastAppliedVersion('prod'));

        $replay = $this->makeManifest(version: 8, nonce: 'n7', env: 'prod');
        $this->expectException(ManifestReplayException::class);
        $restored->assertFresh($replay, $now);
    }

    public function test_snapshot_prunes_nonces_older_than_freshness_window(): void
    {
        $guard = new ManifestFreshnessGuard(freshnessWindowSeconds: 60);
        $now = new \DateTimeImmutable();

        // recordApplied() stamps the nonce with $manifest->issuedAt, so age it out via a
        // manifest whose issuedAt predates the freshness window.
        $guard->recordApplied(new DesiredStateManifest(
            env: 'prod',
            releaseVersion: '1.0.0',
            imageDigest: 'sha256:' . str_repeat('a', 64),
            activeColor: 'blue',
            composeProjection: '{}',
            schemaFingerprint: 'fp',
            issuedAt: $now->modify('-200 seconds'),
            version: 2,
            nonce: 'aged-out',
        ));

        $snapshot = $guard->snapshot('prod', $now);

        $this->assertArrayNotHasKey('aged-out', $snapshot->seenNonces);
    }

    public function test_different_envs_are_independent(): void
    {
        $guard = new ManifestFreshnessGuard();

        $prodManifest = $this->makeManifest(version: 5, nonce: 'n1', env: 'prod');
        $guard->assertFresh($prodManifest, new \DateTimeImmutable());
        $guard->recordApplied($prodManifest);

        $stagingManifest = $this->makeManifest(version: 1, nonce: 'n2', env: 'staging');
        $guard->assertFresh($stagingManifest, new \DateTimeImmutable());

        $this->addToAssertionCount(1);
    }

    public function test_rejects_invalid_freshness_window(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Freshness window must be >= 1');

        new ManifestFreshnessGuard(freshnessWindowSeconds: 0);
    }

    private function makeManifest(int $version = 1, string $nonce = '', string $env = 'prod'): DesiredStateManifest
    {
        if ($nonce === '') {
            $nonce = 'nonce-' . $version . '-' . bin2hex(random_bytes(4));
        }

        return new DesiredStateManifest(
            env: $env,
            releaseVersion: '1.0.0',
            imageDigest: 'sha256:' . str_repeat('a', 64),
            activeColor: 'blue',
            composeProjection: '{}',
            schemaFingerprint: 'fp',
            issuedAt: new \DateTimeImmutable(),
            version: $version,
            nonce: $nonce,
        );
    }
}
