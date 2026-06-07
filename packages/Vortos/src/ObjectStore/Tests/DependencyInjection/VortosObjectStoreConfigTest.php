<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\Tests\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Vortos\ObjectStore\Config\ObjectStoreObservabilitySection;
use Vortos\ObjectStore\DependencyInjection\VortosObjectStoreConfig;

final class VortosObjectStoreConfigTest extends TestCase
{
    public function test_fluent_config_exports_nested_array(): void
    {
        $config = new VortosObjectStoreConfig();
        $config
            ->driver('null')
            ->provider('r2')
            ->region('auto')
            ->endpoint('https://account.r2.cloudflarestorage.com')
            ->bucket('squaura-media');

        $config->client()->accountId('account-id')->pathStyleEndpoint(true)->maxRetries(5);
        $config->bucketConfig()->keyPrefix('/uploads/')->maxUploadSizeBytes(123);

        $array = $config->toArray();

        $this->assertSame('null', $array['driver']);
        $this->assertSame('r2', $array['provider']);
        $this->assertSame('auto', $array['region']);
        $this->assertSame('https://account.r2.cloudflarestorage.com', $array['client']['endpoint']);
        $this->assertSame('account-id', $array['client']['account_id']);
        $this->assertTrue($array['client']['path_style_endpoint']);
        $this->assertSame(5, $array['client']['max_retries']);
        $this->assertSame('squaura-media', $array['bucket']['name']);
        $this->assertSame('uploads', $array['bucket']['key_prefix']);
        $this->assertSame(123, $array['bucket']['max_upload_size_bytes']);
    }

    public function test_observability_section_opt_outs_are_typed(): void
    {
        $config = new VortosObjectStoreConfig();
        $config->observability()
            ->disableLoggingFor(ObjectStoreObservabilitySection::Presign)
            ->disableTracingFor(ObjectStoreObservabilitySection::DirectUpload)
            ->disableMetricsFor(ObjectStoreObservabilitySection::Outbox);

        $array = $config->toArray();

        $this->assertSame([ObjectStoreObservabilitySection::Presign->value], $array['observability']['logging_disabled_for']);
        $this->assertSame([ObjectStoreObservabilitySection::DirectUpload->value], $array['observability']['tracing_disabled_for']);
        $this->assertSame([ObjectStoreObservabilitySection::Outbox->value], $array['observability']['metrics_disabled_for']);
    }

    public function test_lifecycle_config_exports_nested_array(): void
    {
        $config = new VortosObjectStoreConfig();
        $config->lifecycle()
            ->ruleId('managed')
            ->requireConfirmation(false)
            ->roundUpMinimumLifecycleDay(true);

        $array = $config->toArray();

        $this->assertSame('managed', $array['lifecycle']['rule_id']);
        $this->assertFalse($array['lifecycle']['require_confirmation']);
        $this->assertTrue($array['lifecycle']['round_up_minimum_lifecycle_day']);
    }
}
