<?php

declare(strict_types=1);

namespace Vortos\Release\Service;

use Vortos\Release\Git\RawCommit;
use Vortos\Release\Version\ConventionalCommit;
use Vortos\Release\Version\SemverVersion;

final readonly class PackagePlanInput
{
    /**
     * @param list<RawCommit> $rawCommits
     * @param list<ConventionalCommit> $parsedCommits
     */
    public function __construct(
        public string $packageName,
        public string $packagePath,
        public SemverVersion $currentVersion,
        public string $commitRange,
        public array $rawCommits,
        public array $parsedCommits,
        public string $remote,
    ) {}
}
