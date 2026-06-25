<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\PullAgent;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\PullAgent\DesiredStateManifest;

final class DesiredStateManifestTest extends TestCase
{
    public function test_canonical_bytes_are_sorted_and_stable(): void
    {
        $m = $this->makeManifest();
        $bytes1 = $m->toCanonicalBytes();
        $bytes2 = $m->toCanonicalBytes();

        $this->assertSame($bytes1, $bytes2, 'Canonical bytes must be stable across calls.');

        $decoded = json_decode($bytes1, true);
        $keys = array_keys($decoded);
        $sorted = $keys;
        sort($sorted);

        $this->assertSame($sorted, $keys, 'Keys in canonical bytes must be sorted alphabetically.');
    }

    public function test_round_trip_via_array(): void
    {
        $m = $this->makeManifest();
        $restored = DesiredStateManifest::fromArray($m->toArray());

        $this->assertSame($m->toCanonicalBytes(), $restored->toCanonicalBytes());
    }

    public function test_rejects_invalid_digest(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid image digest');

        new DesiredStateManifest(
            env: 'prod',
            releaseVersion: '1.0.0',
            imageDigest: 'not-a-digest',
            activeColor: 'blue',
            composeProjection: '{}',
            schemaFingerprint: 'fp-123',
            issuedAt: new \DateTimeImmutable(),
            version: 1,
            nonce: 'nonce-1',
        );
    }

    public function test_rejects_version_less_than_one(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('version must be >= 1');

        new DesiredStateManifest(
            env: 'prod',
            releaseVersion: '1.0.0',
            imageDigest: 'sha256:' . str_repeat('a', 64),
            activeColor: 'blue',
            composeProjection: '{}',
            schemaFingerprint: 'fp-123',
            issuedAt: new \DateTimeImmutable(),
            version: 0,
            nonce: 'nonce-1',
        );
    }

    public function test_rejects_empty_nonce(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('nonce must not be empty');

        new DesiredStateManifest(
            env: 'prod',
            releaseVersion: '1.0.0',
            imageDigest: 'sha256:' . str_repeat('a', 64),
            activeColor: 'blue',
            composeProjection: '{}',
            schemaFingerprint: 'fp-123',
            issuedAt: new \DateTimeImmutable(),
            version: 1,
            nonce: '',
        );
    }

    public function test_rejects_empty_env(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('env must not be empty');

        new DesiredStateManifest(
            env: '',
            releaseVersion: '1.0.0',
            imageDigest: 'sha256:' . str_repeat('a', 64),
            activeColor: 'blue',
            composeProjection: '{}',
            schemaFingerprint: 'fp-123',
            issuedAt: new \DateTimeImmutable(),
            version: 1,
            nonce: 'nonce-1',
        );
    }

    public function test_accepts_digest_with_at_prefix(): void
    {
        $m = new DesiredStateManifest(
            env: 'prod',
            releaseVersion: '1.0.0',
            imageDigest: '@sha256:' . str_repeat('b', 64),
            activeColor: 'green',
            composeProjection: '{}',
            schemaFingerprint: 'fp',
            issuedAt: new \DateTimeImmutable(),
            version: 1,
            nonce: 'n',
        );

        $this->assertStringStartsWith('@sha256:', $m->imageDigest);
    }

    public function test_to_array_contains_all_fields(): void
    {
        $m = $this->makeManifest();
        $arr = $m->toArray();

        $this->assertArrayHasKey('env', $arr);
        $this->assertArrayHasKey('release_version', $arr);
        $this->assertArrayHasKey('image_digest', $arr);
        $this->assertArrayHasKey('active_color', $arr);
        $this->assertArrayHasKey('compose_projection', $arr);
        $this->assertArrayHasKey('schema_fingerprint', $arr);
        $this->assertArrayHasKey('issued_at', $arr);
        $this->assertArrayHasKey('version', $arr);
        $this->assertArrayHasKey('nonce', $arr);
    }

    private function makeManifest(int $version = 1, string $nonce = 'test-nonce'): DesiredStateManifest
    {
        return new DesiredStateManifest(
            env: 'prod',
            releaseVersion: '1.0.0',
            imageDigest: 'sha256:' . str_repeat('a', 64),
            activeColor: 'blue',
            composeProjection: '{"services":{}}',
            schemaFingerprint: 'fp-abc123',
            issuedAt: new \DateTimeImmutable('2026-06-23T12:00:00+00:00'),
            version: $version,
            nonce: $nonce,
        );
    }
}
