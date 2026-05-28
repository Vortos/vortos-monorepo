<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\Lifecycle;

use Vortos\ObjectStore\Exception\ObjectStoreConfigurationException;

final class LifecycleRule
{
    public function __construct(
        private readonly string $id,
        private readonly string $prefix,
        private readonly int $expirationDays,
        private readonly LifecycleRuleStatus $status = LifecycleRuleStatus::Enabled,
    ) {
        if ($id === '') {
            throw new ObjectStoreConfigurationException('Lifecycle rule ID cannot be empty.');
        }

        if ($expirationDays < 1) {
            throw new ObjectStoreConfigurationException('Lifecycle expiration must be at least one day.');
        }
    }

    public static function temporaryUploadExpiry(
        string $ruleId,
        string $temporaryPrefix,
        int $orphanTtlSeconds,
        bool $roundUpMinimumLifecycleDay = false,
    ): self {
        if ($orphanTtlSeconds < 86400 && !$roundUpMinimumLifecycleDay) {
            throw new ObjectStoreConfigurationException(
                'Object-store lifecycle expiration uses day-level S3 semantics. Set orphan_ttl_seconds to at least 86400 or enable round_up_minimum_lifecycle_day.',
            );
        }

        return new self(
            $ruleId,
            self::normalizePrefix($temporaryPrefix),
            max(1, (int) ceil($orphanTtlSeconds / 86400)),
        );
    }

    public function id(): string
    {
        return $this->id;
    }

    public function prefix(): string
    {
        return $this->prefix;
    }

    public function expirationDays(): int
    {
        return $this->expirationDays;
    }

    public function status(): LifecycleRuleStatus
    {
        return $this->status;
    }

    /** @return array<string, mixed> */
    public function toS3Rule(): array
    {
        return [
            'ID' => $this->id,
            'Status' => $this->status->value,
            'Filter' => ['Prefix' => $this->prefix],
            'Expiration' => ['Days' => $this->expirationDays],
        ];
    }

    private static function normalizePrefix(string $prefix): string
    {
        $prefix = trim($prefix, '/');
        if ($prefix === '') {
            throw new ObjectStoreConfigurationException('Temporary object lifecycle prefix cannot be empty.');
        }

        return $prefix . '/';
    }
}
