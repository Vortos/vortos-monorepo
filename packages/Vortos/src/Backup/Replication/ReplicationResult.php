<?php

declare(strict_types=1);

namespace Vortos\Backup\Replication;

final readonly class ReplicationResult
{
    private function __construct(
        public bool $success,
        public string $primaryKey,
        public ?string $secondaryKey,
        public ?string $error,
    ) {}

    public static function success(string $primaryKey, string $secondaryKey): self
    {
        return new self(true, $primaryKey, $secondaryKey, null);
    }

    public static function failed(string $primaryKey, string $error): self
    {
        return new self(false, $primaryKey, null, $error);
    }

    public static function skipped(string $primaryKey): self
    {
        return new self(true, $primaryKey, null, null);
    }
}
