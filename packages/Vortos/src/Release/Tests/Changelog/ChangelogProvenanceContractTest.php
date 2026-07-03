<?php

declare(strict_types=1);

namespace Vortos\Release\Tests\Changelog;

use PHPUnit\Framework\TestCase;
use Vortos\Release\Changelog\ChangelogRenderer;
use Vortos\Release\Git\RawCommit;
use Vortos\Release\Manifest\BuildManifest;
use Vortos\Release\Manifest\Arch;
use Vortos\Release\ReadModel\ManifestReadModelInterface;
use Vortos\Release\Schema\SchemaFingerprint;
use Vortos\Release\Service\ChangelogGenerator;
use Vortos\Release\Version\ConventionalCommitParser;
use Vortos\Release\Version\SemverVersion;

final class ChangelogProvenanceContractTest extends TestCase
{
    public function test_changelog_references_sha_and_manifest_build_id(): void
    {
        $manifest = new BuildManifest(
            buildId: 'build-42',
            gitSha: str_repeat('a', 40),
            imageRepository: 'ghcr.io/acme/app',
            imageDigest: 'sha256:' . str_repeat('a', 64),
            targetArch: Arch::Arm64,
            environment: 'production',
            schemaFingerprint: new SchemaFingerprint(['m1']),
            createdAt: new \DateTimeImmutable('2026-06-23'),
        );

        $readModel = $this->createMock(ManifestReadModelInterface::class);
        $readModel->method('latestForEnvironment')
            ->with('production')
            ->willReturn($manifest);

        $generator = new ChangelogGenerator(new ConventionalCommitParser(), $readModel);
        $renderer = new ChangelogRenderer();

        $now = new \DateTimeImmutable('2026-06-23');
        $rawCommits = [
            new RawCommit('def5678abcdef5678abcdef5678abcdef5678abc0', 'feat: add SSO support', $now),
        ];

        $changelog = $generator->generate(
            $rawCommits,
            SemverVersion::parse('v1.0.0-alpha-161'),
            'vortos/vortos-release',
            $now,
        );

        $rendered = $renderer->render($changelog);

        // SHA reference (7-char prefix)
        $this->assertStringContainsString('def5678', $rendered);

        // Build manifest ID reference
        $this->assertStringContainsString('build-42', $rendered);

        // Both linked per entry
        $this->assertStringContainsString('(def5678, build build-42)', $rendered);
    }

    public function test_changelog_works_without_manifest(): void
    {
        $generator = new ChangelogGenerator(new ConventionalCommitParser());
        $renderer = new ChangelogRenderer();

        $now = new \DateTimeImmutable('2026-06-23');
        $rawCommits = [
            new RawCommit('abc1234567890abc1234567890abc1234567890ab', 'fix: resolve crash', $now),
        ];

        $changelog = $generator->generate($rawCommits, SemverVersion::parse('v1.0.0'), 'test', $now);
        $rendered = $renderer->render($changelog);

        $this->assertStringContainsString('abc1234', $rendered);
        $this->assertStringNotContainsString('build', $rendered);
    }
}
