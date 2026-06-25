<?php

declare(strict_types=1);

namespace Vortos\Release\Service;

use Vortos\Release\Changelog\Changelog;
use Vortos\Release\Changelog\ChangelogEntry;
use Vortos\Release\Changelog\SecretPatternScrubber;
use Vortos\Release\Git\RawCommit;
use Vortos\Release\ReadModel\ManifestReadModelInterface;
use Vortos\Release\Version\ConventionalCommit;
use Vortos\Release\Version\ConventionalCommitParser;
use Vortos\Release\Version\SemverVersion;

final class ChangelogGenerator
{
    private readonly SecretPatternScrubber $scrubber;

    public function __construct(
        private readonly ConventionalCommitParser $parser,
        private readonly ?ManifestReadModelInterface $manifestReadModel = null,
    ) {
        $this->scrubber = new SecretPatternScrubber();
    }

    /**
     * @param list<RawCommit> $rawCommits
     */
    public function generate(
        array $rawCommits,
        SemverVersion $version,
        string $packageName,
        ?\DateTimeImmutable $date = null,
    ): Changelog {
        $entries = [];
        $breakingEntries = [];

        foreach ($rawCommits as $raw) {
            $commit = $this->parser->parse($raw->rawMessage, $raw->sha);
            $entry = $this->toEntry($commit);

            $entries[] = $entry;

            if ($commit->breaking) {
                $breakingEntries[] = new ChangelogEntry(
                    type: 'breaking',
                    scope: $commit->scope,
                    description: $this->breakingDescription($commit),
                    sha: $commit->sha,
                    buildId: $entry->buildId,
                );
            }
        }

        $allEntries = array_merge($breakingEntries, $entries);

        return Changelog::fromEntries(
            version: $version,
            date: $date ?? new \DateTimeImmutable(),
            entries: $allEntries,
            packageName: $packageName,
        );
    }

    private function toEntry(ConventionalCommit $commit): ChangelogEntry
    {
        $buildId = $this->resolveBuildId();

        return new ChangelogEntry(
            type: $commit->type,
            scope: $commit->scope,
            description: $this->scrubber->scrub($commit->description),
            sha: $commit->sha,
            buildId: $buildId,
        );
    }

    private function resolveBuildId(): ?string
    {
        if ($this->manifestReadModel === null) {
            return null;
        }

        try {
            $manifest = $this->manifestReadModel->latestForEnvironment('production');

            return $manifest?->buildId;
        } catch (\Throwable) {
            return null;
        }
    }

    private function breakingDescription(ConventionalCommit $commit): string
    {
        foreach ($commit->footers as $footer) {
            if (str_starts_with($footer, 'BREAKING CHANGE:')) {
                return $this->scrubber->scrub(trim(substr($footer, 16)));
            }
            if (str_starts_with($footer, 'BREAKING-CHANGE:')) {
                return $this->scrubber->scrub(trim(substr($footer, 16)));
            }
        }

        return $this->scrubber->scrub($commit->description);
    }
}
