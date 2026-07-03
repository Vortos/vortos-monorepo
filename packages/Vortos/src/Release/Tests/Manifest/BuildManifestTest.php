<?php

declare(strict_types=1);

namespace Vortos\Release\Tests\Manifest;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Vortos\Release\Manifest\Arch;
use Vortos\Release\Manifest\BuildManifest;
use Vortos\Release\Manifest\Provenance;
use Vortos\Release\Schema\SchemaFingerprint;

final class BuildManifestTest extends TestCase
{
    private static function validManifest(?Provenance $provenance = null): BuildManifest
    {
        return new BuildManifest(
            buildId: 'build-001',
            gitSha: 'abc1234',
            imageRepository: 'ghcr.io/acme/app',
            imageDigest:'sha256:' . str_repeat('a', 64),
            targetArch: Arch::Arm64,
            environment: 'production',
            schemaFingerprint: new SchemaFingerprint(['m1', 'm2']),
            createdAt: new \DateTimeImmutable('2026-06-23T12:00:00+00:00'),
            provenance: $provenance,
        );
    }

    // ── Valid construction ──

    public function test_valid_manifest(): void
    {
        $m = self::validManifest();
        $this->assertSame('build-001', $m->buildId);
        $this->assertSame('abc1234', $m->gitSha);
        $this->assertSame('ghcr.io/acme/app', $m->imageRepository);
        $this->assertSame('ghcr.io/acme/app@sha256:' . str_repeat('a', 64), $m->pullReference());
        $this->assertSame(Arch::Arm64, $m->targetArch);
        $this->assertSame('production', $m->environment);
        $this->assertSame(['m1', 'm2'], $m->schemaFingerprint->migrationIds);
        $this->assertNull($m->provenance);
    }

    public function test_valid_with_provenance(): void
    {
        $p = new Provenance('github-actions', 'sha256:' . str_repeat('b', 64));
        $m = self::validManifest($p);
        $this->assertSame('github-actions', $m->provenance->builderId);
    }

    public function test_full_40_char_sha(): void
    {
        $m = new BuildManifest(
            buildId: 'b',
            gitSha: str_repeat('a', 40),
            imageRepository: 'ghcr.io/acme/app',
            imageDigest:'sha256:' . str_repeat('a', 64),
            targetArch: Arch::Amd64,
            environment: 'staging',
            schemaFingerprint: SchemaFingerprint::empty(),
            createdAt: new \DateTimeImmutable(),
        );

        $this->assertSame(str_repeat('a', 40), $m->gitSha);
    }

    public function test_short_7_char_sha(): void
    {
        $m = new BuildManifest(
            buildId: 'b',
            gitSha: 'abc1234',
            imageRepository: 'ghcr.io/acme/app',
            imageDigest:'sha256:' . str_repeat('a', 64),
            targetArch: Arch::Amd64,
            environment: 'dev',
            schemaFingerprint: SchemaFingerprint::empty(),
            createdAt: new \DateTimeImmutable(),
        );

        $this->assertSame('abc1234', $m->gitSha);
    }

    // ── Validation rejections ──

    public function test_rejects_empty_build_id(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Build ID');

        new BuildManifest(
            buildId: '',
            gitSha: 'abc1234',
            imageRepository: 'ghcr.io/acme/app',
            imageDigest:'sha256:' . str_repeat('a', 64),
            targetArch: Arch::Arm64,
            environment: 'prod',
            schemaFingerprint: SchemaFingerprint::empty(),
            createdAt: new \DateTimeImmutable(),
        );
    }

    #[DataProvider('invalidGitShaProvider')]
    public function test_rejects_invalid_git_sha(string $sha): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Git SHA');

        new BuildManifest(
            buildId: 'b',
            gitSha: $sha,
            imageRepository: 'ghcr.io/acme/app',
            imageDigest:'sha256:' . str_repeat('a', 64),
            targetArch: Arch::Arm64,
            environment: 'prod',
            schemaFingerprint: SchemaFingerprint::empty(),
            createdAt: new \DateTimeImmutable(),
        );
    }

    /** @return iterable<string, array{string}> */
    public static function invalidGitShaProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'too short (6)' => ['abc123'];
        yield 'too long (41)' => [str_repeat('a', 41)];
        yield 'uppercase' => ['ABC1234'];
        yield 'non-hex' => ['xyz1234'];
        yield 'spaces' => ['abc 234'];
    }

    #[DataProvider('invalidImageRepositoryProvider')]
    public function test_rejects_invalid_image_repository(string $repository): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Image repository');

        new BuildManifest(
            buildId: 'b',
            gitSha: 'abc1234',
            imageRepository: $repository,
            imageDigest: 'sha256:' . str_repeat('a', 64),
            targetArch: Arch::Arm64,
            environment: 'prod',
            schemaFingerprint: SchemaFingerprint::empty(),
            createdAt: new \DateTimeImmutable(),
        );
    }

    /** @return iterable<string, array{string}> */
    public static function invalidImageRepositoryProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'digest suffix' => ['ghcr.io/acme/app@sha256:' . str_repeat('a', 64)];
        yield 'tag suffix' => ['ghcr.io/acme/app:v1'];
        yield 'uppercase' => ['ghcr.io/Acme/App'];
        yield 'trailing slash' => ['ghcr.io/acme/app/'];
        yield 'double slash' => ['ghcr.io//app'];
        yield 'whitespace' => ['ghcr.io/acme app'];
    }

    /** @return iterable<string, array{string}> */
    public static function validImageRepositoryProvider(): iterable
    {
        yield 'bare' => ['app'];
        yield 'dockerhub library' => ['docker.io/library/app'];
        yield 'ghcr nested' => ['ghcr.io/acme/team/app'];
        yield 'host with port' => ['localhost:5000/app'];
        yield 'dashes and dots' => ['registry.example.com/my-team/my.app'];
    }

    #[DataProvider('validImageRepositoryProvider')]
    public function test_accepts_valid_image_repository(string $repository): void
    {
        $m = new BuildManifest(
            buildId: 'b',
            gitSha: 'abc1234',
            imageRepository: $repository,
            imageDigest: 'sha256:' . str_repeat('a', 64),
            targetArch: Arch::Arm64,
            environment: 'prod',
            schemaFingerprint: SchemaFingerprint::empty(),
            createdAt: new \DateTimeImmutable(),
        );

        $this->assertSame($repository, $m->imageRepository);
    }

    #[DataProvider('invalidImageDigestProvider')]
    public function test_rejects_invalid_image_digest(string $digest): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Image digest');

        new BuildManifest(
            buildId: 'b',
            gitSha: 'abc1234',
            imageRepository: 'ghcr.io/acme/app',
            imageDigest:$digest,
            targetArch: Arch::Arm64,
            environment: 'prod',
            schemaFingerprint: SchemaFingerprint::empty(),
            createdAt: new \DateTimeImmutable(),
        );
    }

    /** @return iterable<string, array{string}> */
    public static function invalidImageDigestProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'no prefix' => [str_repeat('a', 64)];
        yield 'wrong prefix' => ['md5:' . str_repeat('a', 64)];
        yield 'too short hash' => ['sha256:' . str_repeat('a', 63)];
        yield 'too long hash' => ['sha256:' . str_repeat('a', 65)];
        yield 'uppercase hex' => ['sha256:' . str_repeat('A', 64)];
        yield 'non-hex' => ['sha256:' . str_repeat('g', 64)];
    }

    public function test_rejects_empty_environment(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Environment');

        new BuildManifest(
            buildId: 'b',
            gitSha: 'abc1234',
            imageRepository: 'ghcr.io/acme/app',
            imageDigest:'sha256:' . str_repeat('a', 64),
            targetArch: Arch::Arm64,
            environment: '',
            schemaFingerprint: SchemaFingerprint::empty(),
            createdAt: new \DateTimeImmutable(),
        );
    }

    // ── Serialization round-trip ──

    public function test_to_array_from_array_round_trip_without_provenance(): void
    {
        $original = self::validManifest();
        $restored = BuildManifest::fromArray($original->toArray());

        $this->assertSame($original->buildId, $restored->buildId);
        $this->assertSame($original->gitSha, $restored->gitSha);
        $this->assertSame($original->imageRepository, $restored->imageRepository);
        $this->assertSame($original->imageDigest, $restored->imageDigest);
        $this->assertSame($original->targetArch, $restored->targetArch);
        $this->assertSame($original->environment, $restored->environment);
        $this->assertTrue($original->schemaFingerprint->equals($restored->schemaFingerprint));
        $this->assertNull($restored->provenance);
    }

    public function test_to_array_from_array_round_trip_with_provenance(): void
    {
        $p = new Provenance(
            'github-actions',
            'sha256:' . str_repeat('b', 64),
            'sig_data',
            'attestation_data',
        );
        $original = self::validManifest($p);
        $restored = BuildManifest::fromArray($original->toArray());

        $this->assertSame('github-actions', $restored->provenance->builderId);
        $this->assertSame('sha256:' . str_repeat('b', 64), $restored->provenance->baseImageDigest);
        $this->assertSame('sig_data', $restored->provenance->signature);
        $this->assertSame('attestation_data', $restored->provenance->attestation);
    }

    public function test_to_array_contains_expected_keys(): void
    {
        $arr = self::validManifest()->toArray();

        $this->assertArrayHasKey('build_id', $arr);
        $this->assertArrayHasKey('git_sha', $arr);
        $this->assertArrayHasKey('image_repository', $arr);
        $this->assertArrayHasKey('image_digest', $arr);
        $this->assertArrayHasKey('target_arch', $arr);
        $this->assertArrayHasKey('environment', $arr);
        $this->assertArrayHasKey('schema_fingerprint', $arr);
        $this->assertArrayHasKey('created_at', $arr);
        $this->assertArrayHasKey('provenance', $arr);
    }

    // ── Arch enum ──

    public function test_arch_values(): void
    {
        $this->assertSame('linux/amd64', Arch::Amd64->value);
        $this->assertSame('linux/arm64', Arch::Arm64->value);
    }

    public function test_arch_from_string(): void
    {
        $this->assertSame(Arch::Arm64, Arch::from('linux/arm64'));
        $this->assertSame(Arch::Amd64, Arch::from('linux/amd64'));
    }

    public function test_arch_from_invalid_string(): void
    {
        $this->expectException(\ValueError::class);
        Arch::from('linux/x86');
    }
}
