<?php

declare(strict_types=1);

namespace Vortos\Backup\Doctor;

/**
 * The result of probing one engine binary (e.g. `pg_dump`): is it on PATH, where, at what major
 * version, and — when the server major is known — is it new enough to operate against that server.
 */
final readonly class BinaryFinding
{
    public function __construct(
        public string $name,
        public bool $required,
        public bool $present,
        public ?string $path,
        public ?int $detectedMajor,
        public bool $versionSatisfied,
        public string $message,
    ) {
    }

    /**
     * A required binary that is absent, or present but too old, is a hard failure. Optional
     * binaries never fail the report (they are informational).
     */
    public function isFailure(): bool
    {
        return $this->required && (!$this->present || !$this->versionSatisfied);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'required' => $this->required,
            'present' => $this->present,
            'path' => $this->path,
            'detected_major' => $this->detectedMajor,
            'version_satisfied' => $this->versionSatisfied,
            'message' => $this->message,
        ];
    }
}
