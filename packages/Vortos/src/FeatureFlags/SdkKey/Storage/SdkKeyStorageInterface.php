<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\SdkKey\Storage;

use Vortos\FeatureFlags\SdkKey\SdkKey;

interface SdkKeyStorageInterface
{
    public function save(SdkKey $key): void;

    public function findById(string $id): ?SdkKey;

    /** @return SdkKey[] */
    public function findByPrefix(string $prefix): array;

    /** @return SdkKey[] */
    public function findByProjectAndEnv(string $projectId, string $environment): array;

    public function updateLastUsed(string $id, \DateTimeImmutable $at): void;

    public function revoke(string $id, \DateTimeImmutable $revokedAt): void;
}
