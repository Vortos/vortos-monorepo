<?php

declare(strict_types=1);

namespace Vortos\Release\Tests\Changelog;

use PHPUnit\Framework\TestCase;
use Vortos\Release\Changelog\Changelog;
use Vortos\Release\Changelog\ChangelogEntry;
use Vortos\Release\Changelog\ChangelogRenderer;
use Vortos\Release\Version\SemverVersion;

final class ChangelogRendererTest extends TestCase
{
    private ChangelogRenderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new ChangelogRenderer();
    }

    public function test_renders_grouped_entries(): void
    {
        $entries = [
            new ChangelogEntry('feat', 'auth', 'add SSO support', 'abc1234def', 'build-42'),
            new ChangelogEntry('fix', null, 'resolve crash on empty input', 'def5678abc', null),
            new ChangelogEntry('feat', null, 'add dark mode', '111222333', null),
        ];

        $changelog = Changelog::fromEntries(
            version: SemverVersion::parse('1.2.0'),
            date: new \DateTimeImmutable('2026-06-23'),
            entries: $entries,
            packageName: 'vortos/vortos-release',
        );

        $output = $this->renderer->render($changelog);

        $this->assertStringContainsString('## [v1.2.0] - 2026-06-23', $output);
        $this->assertStringContainsString('### Features', $output);
        $this->assertStringContainsString('### Bug Fixes', $output);
        $this->assertStringContainsString('**auth:** add SSO support (abc1234, build build-42)', $output);
        $this->assertStringContainsString('- resolve crash on empty input (def5678)', $output);
    }

    public function test_renders_empty_release(): void
    {
        $changelog = Changelog::fromEntries(
            version: SemverVersion::parse('1.0.1'),
            date: new \DateTimeImmutable('2026-06-23'),
            entries: [],
            packageName: 'vortos/vortos-test',
        );

        $output = $this->renderer->render($changelog);

        $this->assertStringContainsString('## [v1.0.1] - 2026-06-23', $output);
        $this->assertStringContainsString('No notable changes.', $output);
    }

    public function test_deterministic_ordering(): void
    {
        $entries = [
            new ChangelogEntry('fix', null, 'fix A', 'a', null),
            new ChangelogEntry('feat', null, 'feat B', 'b', null),
            new ChangelogEntry('chore', null, 'chore C', 'c', null),
        ];

        $changelog = Changelog::fromEntries(
            version: SemverVersion::parse('1.0.0'),
            date: new \DateTimeImmutable('2026-01-01'),
            entries: $entries,
            packageName: 'test',
        );

        $output = $this->renderer->render($changelog);

        $featPos = strpos($output, '### Features');
        $fixPos = strpos($output, '### Bug Fixes');
        $chorePos = strpos($output, '### Chores');

        $this->assertNotFalse($featPos);
        $this->assertNotFalse($fixPos);
        $this->assertNotFalse($chorePos);
        $this->assertLessThan($fixPos, $featPos);
        $this->assertLessThan($chorePos, $fixPos);
    }

    public function test_redacts_secrets_in_description(): void
    {
        $entries = [
            new ChangelogEntry('fix', null, 'fix: set password=s3cr3t in config', 'a', null),
            new ChangelogEntry('fix', null, 'fix: use token ghp_ABCDEFghijklmnopqrstuvwxyz123456789a', 'b', null),
        ];

        $changelog = Changelog::fromEntries(
            version: SemverVersion::parse('1.0.0'),
            date: new \DateTimeImmutable('2026-01-01'),
            entries: $entries,
            packageName: 'test',
        );

        $output = $this->renderer->render($changelog);

        $this->assertStringNotContainsString('s3cr3t', $output);
        $this->assertStringNotContainsString('ghp_', $output);
        $this->assertStringContainsString('***REDACTED***', $output);
    }

    public function test_provenance_with_build_id(): void
    {
        $entries = [
            new ChangelogEntry('feat', null, 'new thing', 'abcdef1234567', 'build-99'),
        ];

        $changelog = Changelog::fromEntries(
            version: SemverVersion::parse('1.0.0'),
            date: new \DateTimeImmutable('2026-01-01'),
            entries: $entries,
            packageName: 'test',
        );

        $output = $this->renderer->render($changelog);

        $this->assertStringContainsString('(abcdef1, build build-99)', $output);
    }

    public function test_provenance_without_build_id(): void
    {
        $entries = [
            new ChangelogEntry('feat', null, 'new thing', 'abcdef1234567', null),
        ];

        $changelog = Changelog::fromEntries(
            version: SemverVersion::parse('1.0.0'),
            date: new \DateTimeImmutable('2026-01-01'),
            entries: $entries,
            packageName: 'test',
        );

        $output = $this->renderer->render($changelog);

        $this->assertStringContainsString('(abcdef1)', $output);
        $this->assertStringNotContainsString('build', $output);
    }
}
