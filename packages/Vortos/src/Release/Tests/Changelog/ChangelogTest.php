<?php

declare(strict_types=1);

namespace Vortos\Release\Tests\Changelog;

use PHPUnit\Framework\TestCase;
use Vortos\Release\Changelog\Changelog;
use Vortos\Release\Changelog\ChangelogEntry;
use Vortos\Release\Version\SemverVersion;

final class ChangelogTest extends TestCase
{
    public function test_groups_entries_by_type(): void
    {
        $entries = [
            new ChangelogEntry('fix', null, 'fix A', 'a', null),
            new ChangelogEntry('feat', null, 'feat B', 'b', null),
            new ChangelogEntry('feat', null, 'feat C', 'c', null),
            new ChangelogEntry('chore', null, 'chore D', 'd', null),
        ];

        $changelog = Changelog::fromEntries(
            version: SemverVersion::parse('1.0.0'),
            date: new \DateTimeImmutable('2026-01-01'),
            entries: $entries,
            packageName: 'test',
        );

        $this->assertCount(2, $changelog->grouped['feat']);
        $this->assertCount(1, $changelog->grouped['fix']);
        $this->assertCount(1, $changelog->grouped['chore']);
    }

    public function test_unknown_types_go_to_other(): void
    {
        $entries = [
            new ChangelogEntry('ci', null, 'update CI', 'a', null),
            new ChangelogEntry('build', null, 'update build', 'b', null),
        ];

        $changelog = Changelog::fromEntries(
            version: SemverVersion::parse('1.0.0'),
            date: new \DateTimeImmutable('2026-01-01'),
            entries: $entries,
            packageName: 'test',
        );

        $this->assertArrayHasKey('other', $changelog->grouped);
        $this->assertCount(2, $changelog->grouped['other']);
    }

    public function test_empty(): void
    {
        $changelog = Changelog::fromEntries(
            version: SemverVersion::parse('1.0.0'),
            date: new \DateTimeImmutable('2026-01-01'),
            entries: [],
            packageName: 'test',
        );

        $this->assertTrue($changelog->isEmpty());
        $this->assertSame([], $changelog->grouped);
    }

    public function test_to_array(): void
    {
        $entries = [new ChangelogEntry('feat', 'core', 'add X', 'sha1', null)];

        $changelog = Changelog::fromEntries(
            version: SemverVersion::parse('1.0.0'),
            date: new \DateTimeImmutable('2026-06-23'),
            entries: $entries,
            packageName: 'vortos/vortos-release',
        );

        $arr = $changelog->toArray();
        $this->assertSame('v1.0.0', $arr['version']);
        $this->assertSame('2026-06-23', $arr['date']);
        $this->assertSame('vortos/vortos-release', $arr['package']);
        $this->assertCount(1, $arr['entries']);
    }

    public function test_label_for_group(): void
    {
        $this->assertSame('Features', Changelog::labelForGroup('feat'));
        $this->assertSame('Bug Fixes', Changelog::labelForGroup('fix'));
        $this->assertSame('Breaking Changes', Changelog::labelForGroup('breaking'));
        $this->assertSame('Other', Changelog::labelForGroup('other'));
        $this->assertSame('Ci', Changelog::labelForGroup('ci'));
    }

    public function test_group_ordering(): void
    {
        $entries = [
            new ChangelogEntry('chore', null, 'chore', 'a', null),
            new ChangelogEntry('feat', null, 'feat', 'b', null),
            new ChangelogEntry('fix', null, 'fix', 'c', null),
        ];

        $changelog = Changelog::fromEntries(
            version: SemverVersion::parse('1.0.0'),
            date: new \DateTimeImmutable('2026-01-01'),
            entries: $entries,
            packageName: 'test',
        );

        $keys = array_keys($changelog->grouped);
        $this->assertSame(['feat', 'fix', 'chore'], $keys);
    }
}
