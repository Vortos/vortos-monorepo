<?php

declare(strict_types=1);

namespace Vortos\Deploy\Preflight;

/**
 * The immutable result of one preflight check.
 *
 * Carries enough context to be both human-actionable (summary + remediation) and
 * machine-parseable (id + category + status). No field is ever populated from secret
 * material — see {@see \Vortos\Deploy\Tests\Architecture\PreflightNoSecretLeakTest}.
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

    public function isFailure(): bool
    {
        return $this->status === PreflightStatus::Fail;
    }

    /** @return array<string, string> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'category' => $this->category->value,
            'status' => $this->status->value,
            'summary' => $this->summary,
            'detail' => $this->detail,
            'remediation' => $this->remediation,
        ];
    }
}
