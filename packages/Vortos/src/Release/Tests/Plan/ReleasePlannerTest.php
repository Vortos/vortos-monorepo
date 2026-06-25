<?php

declare(strict_types=1);

namespace Vortos\Release\Tests\Plan;

use PHPUnit\Framework\TestCase;
use Vortos\Release\Git\RawCommit;
use Vortos\Release\Service\ChangelogGenerator;
use Vortos\Release\Service\PackagePlanInput;
use Vortos\Release\Service\ReleasePlanner;
use Vortos\Release\Version\AlphaCounterStrategy;
use Vortos\Release\Version\BumpCalculator;
use Vortos\Release\Version\BumpLevel;
use Vortos\Release\Version\ConventionalCommit;
use Vortos\Release\Version\ConventionalCommitParser;
use Vortos\Release\Version\SemverVersion;

final class ReleasePlannerTest extends TestCase
{
    private ReleasePlanner $planner;

    protected function setUp(): void
    {
        $parser = new ConventionalCommitParser();
        $changelogGen = new ChangelogGenerator($parser);
        $this->planner = new ReleasePlanner(
            new BumpCalculator(),
            $changelogGen,
            new AlphaCounterStrategy(),
        );
    }

    public function test_plan_with_feat_commits(): void
    {
        $now = new \DateTimeImmutable('2026-06-23');
        $rawCommits = [
            new RawCommit('abc1234', 'feat: add new feature', $now),
            new RawCommit('def5678', 'fix: resolve crash', $now),
        ];

        $parsedCommits = [
            new ConventionalCommit('feat', null, false, 'add new feature', '', [], 'abc1234'),
            new ConventionalCommit('fix', null, false, 'resolve crash', '', [], 'def5678'),
        ];

        $inputs = [
            new PackagePlanInput(
                packageName: 'vortos/vortos-release',
                packagePath: '/tmp/release',
                currentVersion: SemverVersion::parse('v1.0.0-alpha-160'),
                commitRange: 'v1.0.0-alpha-160..HEAD',
                rawCommits: $rawCommits,
                parsedCommits: $parsedCommits,
                remote: 'origin',
            ),
        ];

        $plan = $this->planner->plan($inputs, 'tx-001', now: $now);

        $this->assertSame('tx-001', $plan->txId);
        $this->assertTrue($plan->hasChanges());
        $this->assertCount(1, $plan->packages);
        $this->assertSame('v1.0.0-alpha-161', (string) $plan->packages[0]->nextVersion);
        $this->assertFalse($plan->skewDetected);
    }

    public function test_plan_no_changes(): void
    {
        $inputs = [
            new PackagePlanInput(
                packageName: 'vortos/vortos-test',
                packagePath: '/tmp/test',
                currentVersion: SemverVersion::parse('v1.0.0-alpha-100'),
                commitRange: 'HEAD..HEAD',
                rawCommits: [],
                parsedCommits: [],
                remote: 'origin',
            ),
        ];

        $plan = $this->planner->plan($inputs, 'tx-002');

        $this->assertFalse($plan->hasChanges());
        $this->assertSame([], $plan->packagesWithChanges());
    }

    public function test_plan_with_force_bump(): void
    {
        $now = new \DateTimeImmutable('2026-06-23');
        $rawCommits = [new RawCommit('abc', 'chore: cleanup', $now)];
        $parsedCommits = [new ConventionalCommit('chore', null, false, 'cleanup', '', [], 'abc')];

        $inputs = [
            new PackagePlanInput(
                packageName: 'vortos/vortos-test',
                packagePath: '/tmp/test',
                currentVersion: SemverVersion::parse('v1.0.0-alpha-50'),
                commitRange: 'tag..HEAD',
                rawCommits: $rawCommits,
                parsedCommits: $parsedCommits,
                remote: 'origin',
            ),
        ];

        $plan = $this->planner->plan($inputs, 'tx-003', forceBump: BumpLevel::Minor, now: $now);

        $this->assertTrue($plan->hasChanges());
        $this->assertSame(BumpLevel::Minor, $plan->globalBump);
    }

    public function test_plan_serialization_round_trip(): void
    {
        $now = new \DateTimeImmutable('2026-06-23');
        $rawCommits = [new RawCommit('abc', 'feat: add X', $now)];
        $parsedCommits = [new ConventionalCommit('feat', null, false, 'add X', '', [], 'abc')];

        $inputs = [
            new PackagePlanInput(
                packageName: 'vortos/vortos-release',
                packagePath: '/tmp/release',
                currentVersion: SemverVersion::parse('v1.0.0-alpha-160'),
                commitRange: 'tag..HEAD',
                rawCommits: $rawCommits,
                parsedCommits: $parsedCommits,
                remote: 'origin',
            ),
        ];

        $plan = $this->planner->plan($inputs, 'tx-004', now: $now);
        $arr = $plan->toArray();

        $this->assertSame('tx-004', $arr['tx_id']);
        $this->assertArrayHasKey('packages', $arr);
        $this->assertCount(1, $arr['packages']);
        $this->assertSame('vortos/vortos-release', $arr['packages'][0]['package_name']);
    }

    public function test_plan_render(): void
    {
        $now = new \DateTimeImmutable('2026-06-23');
        $rawCommits = [new RawCommit('abc', 'feat: add X', $now)];
        $parsedCommits = [new ConventionalCommit('feat', null, false, 'add X', '', [], 'abc')];

        $inputs = [
            new PackagePlanInput(
                packageName: 'vortos/vortos-release',
                packagePath: '/tmp/release',
                currentVersion: SemverVersion::parse('v1.0.0-alpha-160'),
                commitRange: 'tag..HEAD',
                rawCommits: $rawCommits,
                parsedCommits: $parsedCommits,
                remote: 'origin',
            ),
        ];

        $plan = $this->planner->plan($inputs, 'tx-005', now: $now);
        $rendered = $plan->render();

        $this->assertStringContainsString('tx-005', $rendered);
        $this->assertStringContainsString('vortos/vortos-release', $rendered);
        $this->assertStringContainsString('v1.0.0-alpha-160', $rendered);
        $this->assertStringContainsString('v1.0.0-alpha-161', $rendered);
    }

    public function test_skew_reported_in_plan(): void
    {
        $plan = $this->planner->plan([], 'tx-006', skewDetected: true);

        $this->assertTrue($plan->skewDetected);
        $this->assertStringContainsString('DETECTED', $plan->render());
    }

    public function test_multiple_packages(): void
    {
        $now = new \DateTimeImmutable('2026-06-23');
        $rawCommits = [new RawCommit('abc', 'feat: add X', $now)];
        $parsedCommits = [new ConventionalCommit('feat', null, false, 'add X', '', [], 'abc')];

        $inputs = [];
        foreach (['vortos/vortos-a', 'vortos/vortos-b', 'vortos/vortos-c'] as $name) {
            $inputs[] = new PackagePlanInput(
                packageName: $name,
                packagePath: '/tmp/' . $name,
                currentVersion: SemverVersion::parse('v1.0.0-alpha-10'),
                commitRange: 'tag..HEAD',
                rawCommits: $rawCommits,
                parsedCommits: $parsedCommits,
                remote: 'origin',
            );
        }

        $plan = $this->planner->plan($inputs, 'tx-007', now: $now);

        $this->assertCount(3, $plan->packages);
        $this->assertCount(3, $plan->packagesWithChanges());
    }
}
