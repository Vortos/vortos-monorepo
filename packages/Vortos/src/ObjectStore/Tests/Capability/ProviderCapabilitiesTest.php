<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\Tests\Capability;

use PHPUnit\Framework\TestCase;
use Vortos\ObjectStore\Capability\ObjectStoreProviderCapability;
use Vortos\ObjectStore\Capability\ProviderCapabilities;
use Vortos\ObjectStore\Exception\ObjectStoreConfigurationException;

final class ProviderCapabilitiesTest extends TestCase
{
    public function test_r2_supports_s3_compatible_core_but_not_aws_only_controls(): void
    {
        $capabilities = ProviderCapabilities::forProvider('r2');

        $this->assertTrue($capabilities->supports(ObjectStoreProviderCapability::BasicObjectOperations));
        $this->assertTrue($capabilities->supports(ObjectStoreProviderCapability::PresignedUrls));
        $this->assertTrue($capabilities->supports(ObjectStoreProviderCapability::MultipartUploads));
        $this->assertTrue($capabilities->supports(ObjectStoreProviderCapability::LifecycleConfiguration));
        $this->assertTrue($capabilities->supports(ObjectStoreProviderCapability::LifecyclePrefixExpiration));
        $this->assertFalse($capabilities->supports(ObjectStoreProviderCapability::KmsEncryption));
    }

    public function test_public_urls_require_configured_public_base_url(): void
    {
        $this->assertFalse(ProviderCapabilities::forProvider('r2')->supports(ObjectStoreProviderCapability::PublicUrls));
        $this->assertTrue(ProviderCapabilities::forProvider('r2', publicBaseUrlConfigured: true)->supports(ObjectStoreProviderCapability::PublicUrls));
    }

    public function test_assert_supported_throws_for_unsupported_capability(): void
    {
        $this->expectException(ObjectStoreConfigurationException::class);
        ProviderCapabilities::forProvider('r2')->assertSupported(ObjectStoreProviderCapability::KmsEncryption);
    }
}
