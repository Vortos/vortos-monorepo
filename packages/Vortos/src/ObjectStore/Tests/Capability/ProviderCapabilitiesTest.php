<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\Tests\Capability;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Vortos\ObjectStore\Capability\ObjectStoreProviderCapability;
use Vortos\ObjectStore\Capability\ProviderCapabilities;
use Vortos\ObjectStore\Exception\ObjectStoreConfigurationException;
use Vortos\ObjectStore\Lifecycle\ObjectStorageClass;

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

    /** @return iterable<string, array{string}> */
    public static function transitionProviders(): iterable
    {
        yield 'r2' => ['r2'];
        yield 'aws_s3' => ['aws_s3'];
        yield 's3' => ['s3'];
    }

    #[DataProvider('transitionProviders')]
    public function test_storage_class_transitions_are_supported_where_the_provider_implements_them(string $provider): void
    {
        $this->assertTrue(ProviderCapabilities::forProvider($provider)->supports(ObjectStoreProviderCapability::LifecycleStorageClassTransition));
    }

    public function test_generic_s3_does_not_claim_storage_class_transitions(): void
    {
        $this->assertFalse(ProviderCapabilities::forProvider('generic_s3')->supports(ObjectStoreProviderCapability::LifecycleStorageClassTransition));
    }

    public function test_aws_refuses_infrequent_access_before_thirty_days(): void
    {
        $this->assertSame(30, ProviderCapabilities::forProvider('aws_s3')->minimumTransitionDays(ObjectStorageClass::InfrequentAccess));
        $this->assertSame(30, ProviderCapabilities::forProvider('s3')->minimumTransitionDays(ObjectStorageClass::InfrequentAccess));
    }

    public function test_r2_accepts_infrequent_access_after_one_day(): void
    {
        $this->assertSame(1, ProviderCapabilities::forProvider('r2')->minimumTransitionDays(ObjectStorageClass::InfrequentAccess));
    }

    public function test_minimum_transition_days_throws_where_transitions_are_unsupported(): void
    {
        $this->expectException(ObjectStoreConfigurationException::class);
        ProviderCapabilities::forProvider('generic_s3')->minimumTransitionDays(ObjectStorageClass::InfrequentAccess);
    }
}
