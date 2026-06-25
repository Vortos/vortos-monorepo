<?php

declare(strict_types=1);

namespace Vortos\Release\Service;

use Vortos\Release\Changelog\Changelog;
use Vortos\Release\Git\RawCommit;
use Vortos\Release\Plan\PackageTagPlan;
use Vortos\Release\Plan\ReleasePlan;
use Vortos\Release\Version\BumpCalculator;
use Vortos\Release\Version\BumpLevel;
use Vortos\Release\Version\SemverVersion;
use Vortos\Release\Version\VersioningStrategyInterface;

final class ReleasePlanner
{
    public function __construct(
        private readonly BumpCalculator $bumpCalculator,
        private readonly ChangelogGenerator $changelogGenerator,
        private readonly VersioningStrategyInterface $strategy,
    ) {}

    /**
     * @param list<PackagePlanInput> $inputs
     */
    public function plan(
        array $inputs,
        string $txId,
        bool $skewDetected = false,
        ?\DateTimeImmutable $now = null,
        ?BumpLevel $forceBump = null,
    ): ReleasePlan {
        $now = $now ?? new \DateTimeImmutable();
        $packages = [];
        $globalBump = BumpLevel::None;

        foreach ($inputs as $input) {
            $bump = $forceBump ?? $this->bumpCalculator->calculate(
                $input->parsedCommits,
                !$input->currentVersion->isStable(),
            );

            $globalBump = BumpLevel::max($globalBump, $bump);

            $nextVersion = $this->strategy->nextVersion($input->currentVersion, $bump);

            $changelog = $this->changelogGenerator->generate(
                rawCommits: $input->rawCommits,
                version: $nextVersion,
                packageName: $input->packageName,
                date: $now,
            );

            $packages[] = new PackageTagPlan(
                packageName: $input->packageName,
                packagePath: $input->packagePath,
                currentVersion: $input->currentVersion,
                nextVersion: $nextVersion,
                bumpLevel: $bump,
                commitRange: $input->commitRange,
                changelog: $changelog,
                remote: $input->remote,
            );
        }

        return new ReleasePlan(
            txId: $txId,
            createdAt: $now,
            packages: $packages,
            globalBump: $globalBump,
            skewDetected: $skewDetected,
        );
    }
}
