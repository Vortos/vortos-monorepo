<?php

declare(strict_types=1);

namespace Vortos\Deploy\Preflight;

/**
 * The aggregate result of a doctor run — the single source consumed by both the
 * 'deploy:doctor' exit code and the 'deploy' go/no-go decision (§5.3: one source,
 * two consumers).
 *
 * Findings are sorted by category then id so the JSON is byte-stable across runs
 * (diffable in CI logs; reproducible in tests). The report is versioned via
 * {@see SCHEMA_VERSION} so Block 14's pipeline can pin the contract.
 */
final readonly class PreflightReport
{
    public const SCHEMA_VERSION = '1.0';

    /** @var list<PreflightFinding> sorted by category sortOrder then id */
    public array $findings;

    /**
     * @param list<PreflightFinding> $findings
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

    public function isClear(): bool
    {
        foreach ($this->findings as $finding) {
            if ($finding->isFailure()) {
                return false;
            }
        }

        return true;
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

    /** @return list<PreflightFinding> */
    public function failures(): array
    {
        return array_values(array_filter(
            $this->findings,
            static fn (PreflightFinding $f): bool => $f->isFailure(),
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
                'fail' => $this->countByStatus(PreflightStatus::Fail),
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
