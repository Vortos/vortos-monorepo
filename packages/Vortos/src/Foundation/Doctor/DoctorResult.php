<?php

declare(strict_types=1);

namespace Vortos\Foundation\Doctor;

final readonly class DoctorResult
{
    public function __construct(
        public readonly string $name,
        public readonly DoctorStatus $status,
        public readonly string $summary,
        public readonly ?string $detail = null,
        public readonly ?string $fix    = null,
    ) {}

    public static function ok(string $name, string $summary): self
    {
        return new self($name, DoctorStatus::Ok, $summary);
    }

    public static function warning(string $name, string $summary, ?string $fix = null): self
    {
        return new self($name, DoctorStatus::Warning, $summary, fix: $fix);
    }

    public static function error(string $name, string $summary, ?string $fix = null): self
    {
        return new self($name, DoctorStatus::Error, $summary, fix: $fix);
    }
}
