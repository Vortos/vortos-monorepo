<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Integration\PullAgent;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Cutover\ReconcileRateLimiter;
use Vortos\Deploy\Driver\ReleaseKey\ReleaseKeyManifestSigner;
use Vortos\Deploy\Driver\ReleaseKey\ReleaseKeyManifestVerifier;
use Vortos\Deploy\Exception\ManifestReplayException;
use Vortos\Deploy\Exception\ManifestSignatureInvalidException;
use Vortos\Deploy\Exception\StaleManifestException;
use Vortos\Deploy\Exception\UnsignedManifestException;
use Vortos\Deploy\PullAgent\DesiredStateApplier;
use Vortos\Deploy\PullAgent\DesiredStateManifest;
use Vortos\Deploy\PullAgent\ManifestFreshnessGuard;
use Vortos\Deploy\PullAgent\PullAgentReconciler;
use Vortos\Deploy\PullAgent\SignedDesiredStateManifest;
use Vortos\Deploy\Tests\Fixtures\FakeDeployStateStore;
use Vortos\Deploy\Tests\Fixtures\InMemoryRateLimitStateStore;
use Vortos\Deploy\Tests\Fixtures\FakeManifestSource;
use Vortos\Secrets\Value\SecretValue;

final class PullAgentReconcilerTest extends TestCase
{
    private string $secretKey;
    private string $publicKey;
    private ReleaseKeyManifestSigner $signer;
    private ReleaseKeyManifestVerifier $verifier;

    protected function setUp(): void
    {
        $keypair = sodium_crypto_sign_keypair();
        $this->secretKey = sodium_crypto_sign_secretkey($keypair);
        $this->publicKey = sodium_crypto_sign_publickey($keypair);
        $this->signer = new ReleaseKeyManifestSigner(SecretValue::fromString($this->secretKey), 'key-1');
        $this->verifier = new ReleaseKeyManifestVerifier($this->publicKey);
    }

    public function test_no_manifest_available(): void
    {
        $reconciler = $this->makeReconciler(new FakeManifestSource());
        $result = $reconciler->reconcile('prod');

        $this->assertFalse($result->applied);
        $this->assertFalse($result->alreadyCurrent);
    }

    public function test_valid_manifest_applied(): void
    {
        $source = new FakeManifestSource();
        $manifest = $this->makeManifest(version: 1);
        $source->set('prod', $this->signer->sign($manifest));

        $reconciler = $this->makeReconciler($source);
        $result = $reconciler->reconcile('prod');

        $this->assertTrue($result->applied);
        $this->assertSame(1, $result->appliedVersion);
    }

    public function test_already_applied_version_is_noop(): void
    {
        $source = new FakeManifestSource();
        $manifest = $this->makeManifest(version: 1);
        $source->set('prod', $this->signer->sign($manifest));

        $reconciler = $this->makeReconciler($source);

        $result1 = $reconciler->reconcile('prod');
        $this->assertTrue($result1->applied);

        $result2 = $reconciler->reconcile('prod');
        $this->assertFalse($result2->applied);
        $this->assertTrue($result2->alreadyCurrent);
    }

    public function test_unsigned_manifest_rejected(): void
    {
        $source = new FakeManifestSource();
        $manifest = $this->makeManifest(version: 1);
        $unsigned = new SignedDesiredStateManifest(
            manifest: $manifest,
            signature: '',
            signerKeyId: 'key-1',
        );
        $source->set('prod', $unsigned);

        $reconciler = $this->makeReconciler($source);

        $this->expectException(UnsignedManifestException::class);

        $reconciler->reconcile('prod');
    }

    public function test_tampered_manifest_rejected(): void
    {
        $source = new FakeManifestSource();
        $original = $this->makeManifest(version: 1);
        $signed = $this->signer->sign($original);

        $tampered = new SignedDesiredStateManifest(
            manifest: new DesiredStateManifest(
                env: 'HACKED',
                releaseVersion: $original->releaseVersion,
                imageDigest: $original->imageDigest,
                activeColor: $original->activeColor,
                composeProjection: $original->composeProjection,
                schemaFingerprint: $original->schemaFingerprint,
                issuedAt: $original->issuedAt,
                version: $original->version,
                nonce: $original->nonce,
            ),
            signature: $signed->signature,
            signerKeyId: $signed->signerKeyId,
        );
        $source->set('HACKED', $tampered);

        $reconciler = $this->makeReconciler($source);

        $this->expectException(ManifestSignatureInvalidException::class);

        $reconciler->reconcile('HACKED');
    }

    public function test_rollback_version_rejected(): void
    {
        $source = new FakeManifestSource();

        $m2 = $this->makeManifest(version: 2, nonce: 'n2');
        $source->set('prod', $this->signer->sign($m2));

        $reconciler = $this->makeReconciler($source);
        $reconciler->reconcile('prod');

        $m1 = $this->makeManifest(version: 1, nonce: 'n1');
        $source->set('prod', $this->signer->sign($m1));

        $this->expectException(StaleManifestException::class);

        $reconciler->reconcile('prod');
    }

    public function test_replayed_nonce_rejected(): void
    {
        $source = new FakeManifestSource();

        $m1 = $this->makeManifest(version: 1, nonce: 'replayed-nonce');
        $source->set('prod', $this->signer->sign($m1));

        $reconciler = $this->makeReconciler($source);
        $reconciler->reconcile('prod');

        $m2 = $this->makeManifest(version: 2, nonce: 'replayed-nonce');
        $source->set('prod', $this->signer->sign($m2));

        $this->expectException(ManifestReplayException::class);

        $reconciler->reconcile('prod');
    }

    public function test_stale_issued_at_rejected(): void
    {
        $source = new FakeManifestSource();
        $stale = new DesiredStateManifest(
            env: 'prod',
            releaseVersion: '1.0.0',
            imageDigest: 'sha256:' . str_repeat('a', 64),
            activeColor: 'blue',
            composeProjection: '{}',
            schemaFingerprint: 'fp',
            issuedAt: (new \DateTimeImmutable())->modify('-1200 seconds'),
            version: 1,
            nonce: 'stale-nonce',
        );
        $source->set('prod', $this->signer->sign($stale));

        $reconciler = $this->makeReconciler($source, freshnessWindow: 60);

        $this->expectException(StaleManifestException::class);

        $reconciler->reconcile('prod');
    }

    public function test_rate_limited_not_applied(): void
    {
        $source = new FakeManifestSource();

        $m1 = $this->makeManifest(version: 1, nonce: 'n1');
        $source->set('prod', $this->signer->sign($m1));

        $rateLimiter = new ReconcileRateLimiter(new InMemoryRateLimitStateStore(), minIntervalSeconds: 9999);
        $reconciler = $this->makeReconciler($source, rateLimiter: $rateLimiter);

        $reconciler->reconcile('prod');

        $m2 = $this->makeManifest(version: 2, nonce: 'n2');
        $source->set('prod', $this->signer->sign($m2));

        $result = $reconciler->reconcile('prod');

        $this->assertFalse($result->applied);
        $this->assertStringContainsString('rate-limited', $result->detail);
    }

    public function test_freshness_state_survives_a_simulated_process_restart(): void
    {
        $source = new FakeManifestSource();
        $sharedFreshnessStore = new FakeDeployStateStore();

        $m1 = $this->makeManifest(version: 1, nonce: 'n1');
        $source->set('prod', $this->signer->sign($m1));

        // First "process": applies version 1, persists freshness state, then exits —
        // its in-memory ManifestFreshnessGuard is discarded.
        $reconciler1 = $this->makeReconciler($source, freshnessStore: $sharedFreshnessStore);
        $result1 = $reconciler1->reconcile('prod');
        $this->assertTrue($result1->applied);

        // A brand-new "process": a fresh ManifestFreshnessGuard with no in-memory state,
        // sharing only the persisted store. Replaying the same nonce must still be
        // rejected — proving anti-replay survives a restart, not just a single process.
        $replay = $this->makeManifest(version: 2, nonce: 'n1');
        $source->set('prod', $this->signer->sign($replay));

        $reconciler2 = $this->makeReconciler($source, freshnessStore: $sharedFreshnessStore);

        $this->expectException(ManifestReplayException::class);

        $reconciler2->reconcile('prod');
    }

    public function test_rollback_rejected_across_a_simulated_process_restart(): void
    {
        $source = new FakeManifestSource();
        $sharedFreshnessStore = new FakeDeployStateStore();

        $m2 = $this->makeManifest(version: 2, nonce: 'n2');
        $source->set('prod', $this->signer->sign($m2));

        $reconciler1 = $this->makeReconciler($source, freshnessStore: $sharedFreshnessStore);
        $reconciler1->reconcile('prod');

        // Fresh guard, persisted store only — an old version must still be rejected.
        $m1 = $this->makeManifest(version: 1, nonce: 'n1');
        $source->set('prod', $this->signer->sign($m1));

        $reconciler2 = $this->makeReconciler($source, freshnessStore: $sharedFreshnessStore);

        $this->expectException(StaleManifestException::class);

        $reconciler2->reconcile('prod');
    }

    private function makeReconciler(
        FakeManifestSource $source,
        int $freshnessWindow = 600,
        ?ReconcileRateLimiter $rateLimiter = null,
        ?FakeDeployStateStore $freshnessStore = null,
    ): PullAgentReconciler {
        $stateStore = new FakeDeployStateStore();

        return new PullAgentReconciler(
            source: $source,
            verifier: $this->verifier,
            freshnessGuard: new ManifestFreshnessGuard($freshnessWindow),
            freshnessStore: $freshnessStore ?? new FakeDeployStateStore(),
            applier: new DesiredStateApplier($stateStore, new \Vortos\Deploy\Compose\ComposeProjectFactory(new \Vortos\Deploy\Runtime\RuntimeServiceSpec())),
            rateLimiter: $rateLimiter ?? new ReconcileRateLimiter(new InMemoryRateLimitStateStore()),
        );
    }

    private function makeManifest(int $version = 1, string $nonce = ''): DesiredStateManifest
    {
        if ($nonce === '') {
            $nonce = 'nonce-v' . $version . '-' . bin2hex(random_bytes(4));
        }

        return new DesiredStateManifest(
            env: 'prod',
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
