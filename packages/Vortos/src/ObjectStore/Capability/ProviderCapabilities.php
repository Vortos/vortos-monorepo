<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\Capability;

use Vortos\ObjectStore\Exception\ObjectStoreConfigurationException;
use Vortos\ObjectStore\Lifecycle\ObjectStorageClass;

final class ProviderCapabilities
{
    private const AWS_PROVIDERS = ['aws_s3', 's3'];

    /** @param array<string, true> $capabilities */
    private function __construct(
        private readonly string $provider,
        private readonly array $capabilities,
    ) {}

    public static function forProvider(string $provider, bool $publicBaseUrlConfigured = false): self
    {
        $common = [
            ObjectStoreProviderCapability::BasicObjectOperations->value => true,
            ObjectStoreProviderCapability::PresignedUrls->value => true,
            ObjectStoreProviderCapability::PostPolicyUploads->value => true,
            ObjectStoreProviderCapability::MultipartUploads->value => true,
            ObjectStoreProviderCapability::LifecycleConfiguration->value => true,
            ObjectStoreProviderCapability::LifecyclePrefixExpiration->value => true,
        ];

        if ($publicBaseUrlConfigured) {
            $common[ObjectStoreProviderCapability::PublicUrls->value] = true;
        }

        if (in_array(strtolower($provider), ['aws_s3', 's3', 'generic_s3'], true)) {
            $common[ObjectStoreProviderCapability::ObjectAcl->value] = true;
            $common[ObjectStoreProviderCapability::ObjectLock->value] = true;
            $common[ObjectStoreProviderCapability::ObjectTagging->value] = true;
            $common[ObjectStoreProviderCapability::KmsEncryption->value] = true;
        }

        // Storage-class transitions are provider-specific: R2 (Infrequent Access) and AWS S3 implement
        // them; generic S3-compatible servers (MinIO and friends) only transition to remote tiers
        // configured out of band, so claiming support there would fail at apply time instead of plan.
        if (in_array(strtolower($provider), ['r2', ...self::AWS_PROVIDERS], true)) {
            $common[ObjectStoreProviderCapability::LifecycleStorageClassTransition->value] = true;
        }

        return new self($provider, $common);
    }

    public function provider(): string
    {
        return $this->provider;
    }

    public function supports(ObjectStoreProviderCapability $capability): bool
    {
        return isset($this->capabilities[$capability->value]);
    }

    public function assertSupported(ObjectStoreProviderCapability $capability): void
    {
        if (!$this->supports($capability)) {
            throw new ObjectStoreConfigurationException(sprintf(
                'Object store provider "%s" does not support capability "%s".',
                $this->provider,
                $capability->value,
            ));
        }
    }

    /**
     * The earliest age, in days, at which the provider accepts a transition into $class.
     *
     * AWS rejects a STANDARD_IA transition before 30 days; R2 has no such floor (it bills a 30-day
     * minimum storage duration instead). Checking here makes a bad declaration fail at `plan` rather
     * than half-way through an apply.
     */
    public function minimumTransitionDays(ObjectStorageClass $class): int
    {
        $this->assertSupported(ObjectStoreProviderCapability::LifecycleStorageClassTransition);

        return match ($class) {
            ObjectStorageClass::InfrequentAccess => in_array(strtolower($this->provider), self::AWS_PROVIDERS, true) ? 30 : 1,
        };
    }

    /** @return ObjectStoreProviderCapability[] */
    public function supported(): array
    {
        return array_values(array_filter(
            ObjectStoreProviderCapability::cases(),
            fn(ObjectStoreProviderCapability $capability): bool => $this->supports($capability),
        ));
    }
}
