<?php

declare(strict_types=1);

namespace Vortos\Deploy\Preflight;

final readonly class PreflightReport
{
    /**
     * 1.1 adds "disposition" to every finding and "advisory" to the summary. Additive: a 1.0 reader
     * that looks only at "clear" still gets the right answer.
     */
    public const SCHEMA_VERSION = '1.1';

    /** @var list<PreflightFinding> sorted by category sortOrder then id */
    public array $findings;

    /**
     * @param list<PreflightFinding> $findings
     * @param bool $strict treat advisory failures as blocking
     */
    public function __construct(
        public string $environment,
        array $findings,
        public bool $strict = false,
    ) {
        usort($findings, static function (PreflightFinding $a, PreflightFinding $b): int {
            return [$a->category->sortOrder(), $a->id] <=> [$b->category->sortOrder(), $b->id];
        });

        $this->findings = $findings;
    }

    /** No failure that refuses this release. Advisory failures do not count unless strict. */
    public function isClear(): bool
    {
        return $this->failures() === [];
    }

    public function exitCode(): int
    {
        return $this->isClear() ? 0 : 1;
    }

    public function countByStatus(PreflightStatus $status): int
    {
        $count = 0;
        foreach ($this->findings as $finding) {
            if ($finding->status === $status) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * The failures that refuse this release.
     *
     * @return list<PreflightFinding>
     */
    public function failures(): array
    {
        return array_values(array_filter(
            $this->findings,
            fn (PreflightFinding $f): bool => $f->blocksRelease($this->strict),
        ));
    }

    /**
     * Failures describing the running system: reported on every run, never a veto on their own.
     *
     * @return list<PreflightFinding>
     */
    public function advisories(): array
    {
        return array_values(array_filter(
            $this->findings,
            fn (PreflightFinding $f): bool => $f->isAdvisoryFailure($this->strict),
        ));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'env' => $this->environment,
            'clear' => $this->isClear(),
            'strict' => $this->strict,
            'summary' => [
                'pass' => $this->countByStatus(PreflightStatus::Pass),
                'fail' => count($this->failures()),
                'advisory' => count($this->advisories()),
                'skip' => $this->countByStatus(PreflightStatus::Skip),
            ],
            'findings' => array_map(
                static fn (PreflightFinding $f): array => $f->toArray(),
                $this->findings,
            ),
        ];
    }

    public function toJson(): string
    {
        return json_encode(
            $this->toArray(),
            \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES,
        );
    }
}
