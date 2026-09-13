<?php

declare(strict_types=1);

namespace Vortos\Deploy\Preflight;

use Vortos\OpsKit\Gate\GateDisposition;

/**
 * The outcome of one preflight check.
 *
 * Whether a failure stops the release is the CHECK's declaration
 * ({@see PreflightCheckInterface::disposition()}); {@see DeployDoctor} stamps it onto every finding.
 * A check may narrow an individual finding to Advisory with {@see withDisposition()} when it
 * aggregates sub-checks of mixed kinds. A finding that never passed through the doctor carries no
 * disposition and is treated as Blocking — fail closed.
 */
final readonly class PreflightFinding
{
    public function __construct(
        public string $id,
        public PreflightCategory $category,
        public PreflightStatus $status,
        public string $summary,
        public string $detail = '',
        public string $remediation = '',
        public ?GateDisposition $declaredDisposition = null,
    ) {
        if ($id === '') {
            throw new \InvalidArgumentException('Preflight finding id must not be empty.');
        }
        if ($summary === '') {
            throw new \InvalidArgumentException('Preflight finding summary must not be empty.');
        }
    }

    public static function pass(string $id, PreflightCategory $category, string $summary, string $detail = ''): self
    {
        return new self($id, $category, PreflightStatus::Pass, $summary, $detail);
    }

    public static function fail(
        string $id,
        PreflightCategory $category,
        string $summary,
        string $detail = '',
        string $remediation = '',
    ): self {
        return new self($id, $category, PreflightStatus::Fail, $summary, $detail, $remediation);
    }

    public static function skip(string $id, PreflightCategory $category, string $summary, string $detail = ''): self
    {
        return new self($id, $category, PreflightStatus::Skip, $summary, $detail);
    }

    public function withDisposition(GateDisposition $disposition): self
    {
        return new self(
            $this->id,
            $this->category,
            $this->status,
            $this->summary,
            $this->detail,
            $this->remediation,
            $disposition,
        );
    }

    /** Blocking unless something declared otherwise. */
    public function disposition(): GateDisposition
    {
        return $this->declaredDisposition ?? GateDisposition::Blocking;
    }

    public function isFailure(): bool
    {
        return $this->status === PreflightStatus::Fail;
    }

    /** A failure that refuses the release; with $strict, advisory failures do too. */
    public function blocksRelease(bool $strict = false): bool
    {
        return $this->isFailure() && $this->disposition()->blocksRelease($strict);
    }

    /** A failure that is reported but does not, on its own, refuse the release. */
    public function isAdvisoryFailure(bool $strict = false): bool
    {
        return $this->isFailure() && !$this->disposition()->blocksRelease($strict);
    }

    /** @return array<string, string> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'category' => $this->category->value,
            'status' => $this->status->value,
            'disposition' => $this->disposition()->value,
            'summary' => $this->summary,
            'detail' => $this->detail,
            'remediation' => $this->remediation,
        ];
    }
}
