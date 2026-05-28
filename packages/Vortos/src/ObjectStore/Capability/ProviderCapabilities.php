<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\Capability;

use Vortos\ObjectStore\Exception\ObjectStoreConfigurationException;

final class ProviderCapabilities
{
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

    /** @return ObjectStoreProviderCapability[] */
    public function supported(): array
    {
        return array_values(array_filter(
            ObjectStoreProviderCapability::cases(),
            fn(ObjectStoreProviderCapability $capability): bool => $this->supports($capability),
        ));
    }
}
