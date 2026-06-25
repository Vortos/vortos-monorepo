<?php

declare(strict_types=1);

namespace Vortos\Release\Tests\Service;

use PHPUnit\Framework\TestCase;
use Vortos\Release\Git\RawCommit;
use Vortos\Release\Service\ChangelogGenerator;
use Vortos\Release\Version\ConventionalCommitParser;
use Vortos\Release\Version\SemverVersion;

final class ChangelogGeneratorTest extends TestCase
{
    private ChangelogGenerator $generator;

    protected function setUp(): void
    {
        $this->generator = new ChangelogGenerator(new ConventionalCommitParser());
    }

    public function test_generates_from_raw_commits(): void
    {
        $now = new \DateTimeImmutable('2026-06-23');
        $rawCommits = [
            new RawCommit('abc1234', 'feat: add feature', $now),
            new RawCommit('def5678', 'fix: resolve crash', $now),
        ];

        $changelog = $this->generator->generate(
            $rawCommits,
            SemverVersion::parse('v1.0.0-alpha-161'),
            'vortos/vortos-release',
            $now,
        );

        $this->assertFalse($changelog->isEmpty());
        $this->assertSame('vortos/vortos-release', $changelog->packageName);
        $this->assertArrayHasKey('feat', $changelog->grouped);
        $this->assertArrayHasKey('fix', $changelog->grouped);
    }

    public function test_breaking_change_generates_breaking_entry(): void
    {
        $now = new \DateTimeImmutable('2026-06-23');
        $rawCommits = [
            new RawCommit('abc', "feat!: remove old API\n\nBREAKING CHANGE: the /v1 endpoint is gone", $now),
        ];

        $changelog = $this->generator->generate(
            $rawCommits,
            SemverVersion::parse('v2.0.0'),
            'test',
            $now,
        );

        $this->assertArrayHasKey('breaking', $changelog->grouped);
        $this->assertSame('the /v1 endpoint is gone', $changelog->grouped['breaking'][0]->description);
    }

    public function test_empty_commits(): void
    {
        $changelog = $this->generator->generate(
            [],
            SemverVersion::parse('v1.0.0'),
            'test',
            new \DateTimeImmutable(),
        );

        $this->assertTrue($changelog->isEmpty());
    }

    public function test_non_conventional_commits_included(): void
    {
        $now = new \DateTimeImmutable('2026-06-23');
        $rawCommits = [
            new RawCommit('abc', 'benchmark fix', $now),
        ];

        $changelog = $this->generator->generate($rawCommits, SemverVersion::parse('v1.0.0'), 'test', $now);

        $this->assertFalse($changelog->isEmpty());
        $this->assertArrayHasKey('other', $changelog->grouped);
    }
}
