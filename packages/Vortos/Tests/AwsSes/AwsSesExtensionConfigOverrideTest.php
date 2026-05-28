<?php

declare(strict_types=1);

namespace Vortos\Tests\AwsSes;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Vortos\AwsSes\Config\AwsSesObservabilitySection;
use Vortos\AwsSes\DependencyInjection\Configuration;
use Vortos\AwsSes\DependencyInjection\VortosAwsSesConfig;

/**
 * Verifies that values set via the fluent VortosAwsSesConfig builder survive
 * the Symfony config tree processor and appear in the resolved array that
 * AwsSesExtension uses to set container parameters.
 */
final class AwsSesExtensionConfigOverrideTest extends TestCase
{
    private function resolve(VortosAwsSesConfig $config): array
    {
        return (new Processor())->processConfiguration(new Configuration(), [$config->toArray()]);
    }

    public function test_driver_override(): void
    {
        $config = new VortosAwsSesConfig();
        $config->driver('ses');

        $this->assertSame('ses', $this->resolve($config)['driver']);
    }

    public function test_region_override(): void
    {
        $config = new VortosAwsSesConfig();
        $config->region('eu-west-1');

        $this->assertSame('eu-west-1', $this->resolve($config)['region']);
    }

    public function test_fallback_region_override(): void
    {
        $config = new VortosAwsSesConfig();
        $config->fallbackRegion('ap-southeast-1');

        $this->assertSame('ap-southeast-1', $this->resolve($config)['fallback_region']);
    }

    public function test_default_from_override(): void
    {
        $config = new VortosAwsSesConfig();
        $config->defaultFrom('noreply@example.com', 'My App');

        $resolved = $this->resolve($config);
        $this->assertSame('noreply@example.com', $resolved['default_from_address']);
        $this->assertSame('My App', $resolved['default_from_name']);
    }

    public function test_reply_to_override(): void
    {
        $config = new VortosAwsSesConfig();
        $config->replyTo('support@example.com');

        $this->assertSame('support@example.com', $this->resolve($config)['reply_to']);
    }

    public function test_configuration_set_override(): void
    {
        $config = new VortosAwsSesConfig();
        $config->configurationSet('my-config-set');

        $this->assertSame('my-config-set', $this->resolve($config)['configuration_set']);
    }

    public function test_aws_client_localstack_endpoint(): void
    {
        $config = new VortosAwsSesConfig();
        $config->awsClient()->endpointOverride('http://localstack:4566');

        $this->assertSame('http://localstack:4566', $this->resolve($config)['aws_client']['endpoint_override']);
    }

    public function test_aws_client_timeout_and_retries(): void
    {
        $config = new VortosAwsSesConfig();
        $config->awsClient()->httpTimeout(5.0)->maxRetries(5);

        $resolved = $this->resolve($config);
        $this->assertSame(5.0, $resolved['aws_client']['http_timeout']);
        $this->assertSame(5, $resolved['aws_client']['max_retries']);
    }

    public function test_outbox_custom_table_name(): void
    {
        $config = new VortosAwsSesConfig();
        $config->outbox()->tableName('custom_email_outbox');

        $this->assertSame('custom_email_outbox', $this->resolve($config)['outbox']['table_name']);
    }

    public function test_outbox_disabled(): void
    {
        $config = new VortosAwsSesConfig();
        $config->outbox()->enabled(false);

        $this->assertFalse($this->resolve($config)['outbox']['enabled']);
    }

    public function test_outbox_batch_size(): void
    {
        $config = new VortosAwsSesConfig();
        $config->outbox()->batchSize(100);

        $this->assertSame(100, $this->resolve($config)['outbox']['batch_size']);
    }

    public function test_outbox_max_delivery_attempts(): void
    {
        $config = new VortosAwsSesConfig();
        $config->outbox()->maxDeliveryAttempts(3);

        $this->assertSame(3, $this->resolve($config)['outbox']['max_delivery_attempts']);
    }

    public function test_outbox_backoff_settings(): void
    {
        $config = new VortosAwsSesConfig();
        $config->outbox()->backoffBaseSeconds(60)->backoffCapSeconds(7200);

        $resolved = $this->resolve($config);
        $this->assertSame(60, $resolved['outbox']['backoff_base_seconds']);
        $this->assertSame(7200, $resolved['outbox']['backoff_cap_seconds']);
    }

    public function test_webhooks_disabled(): void
    {
        $config = new VortosAwsSesConfig();
        $config->webhooks()->enabled(false);

        $this->assertFalse($this->resolve($config)['webhooks']['enabled']);
    }

    public function test_webhooks_custom_route_path(): void
    {
        $config = new VortosAwsSesConfig();
        $config->webhooks()->routePath('/_internal/aws/ses');

        $this->assertSame('/_internal/aws/ses', $this->resolve($config)['webhooks']['route_path']);
    }

    public function test_suppression_strip_mode(): void
    {
        $config = new VortosAwsSesConfig();
        $config->suppression()->onSuppressed('strip');

        $this->assertSame('strip', $this->resolve($config)['suppression']['on_suppressed']);
    }

    public function test_suppression_sync_on_startup(): void
    {
        $config = new VortosAwsSesConfig();
        $config->suppression()->syncOnStartup(true);

        $this->assertTrue($this->resolve($config)['suppression']['sync_on_startup']);
    }

    public function test_rate_limit_custom_send_rate(): void
    {
        $config = new VortosAwsSesConfig();
        $config->rateLimit()->maxSendRate(50)->burst(100);

        $resolved = $this->resolve($config);
        $this->assertSame(50, $resolved['rate_limit']['max_send_rate']);
        $this->assertSame(100, $resolved['rate_limit']['burst']);
    }

    public function test_circuit_breaker_custom_thresholds(): void
    {
        $config = new VortosAwsSesConfig();
        $config->circuitBreaker()->failureThreshold(10)->resetTimeoutSeconds(120);

        $resolved = $this->resolve($config);
        $this->assertSame(10, $resolved['circuit_breaker']['failure_threshold']);
        $this->assertSame(120, $resolved['circuit_breaker']['reset_timeout_seconds']);
    }

    public function test_audit_log_disabled(): void
    {
        $config = new VortosAwsSesConfig();
        $config->auditLog()->enabled(false);

        $this->assertFalse($this->resolve($config)['audit_log']['enabled']);
    }

    public function test_audit_log_custom_table_name(): void
    {
        $config = new VortosAwsSesConfig();
        $config->auditLog()->tableName('my_email_audit');

        $this->assertSame('my_email_audit', $this->resolve($config)['audit_log']['table_name']);
    }

    public function test_observability_can_be_opted_out_by_typed_section(): void
    {
        $config = new VortosAwsSesConfig();
        $config->observability()
            ->logging(false)
            ->disableTracingFor(AwsSesObservabilitySection::Send)
            ->disableMetricsFor(AwsSesObservabilitySection::Outbox);

        $resolved = $this->resolve($config);

        $this->assertFalse($resolved['observability']['logging']);
        $this->assertSame([AwsSesObservabilitySection::Send->value], $resolved['observability']['tracing_disabled_for']);
        $this->assertSame([AwsSesObservabilitySection::Outbox->value], $resolved['observability']['metrics_disabled_for']);
    }

    public function test_invalid_driver_is_rejected(): void
    {
        $config = new VortosAwsSesConfig();
        $config->driver('smtp');

        $this->expectException(InvalidConfigurationException::class);
        $this->resolve($config);
    }

    public function test_invalid_suppression_behavior_is_rejected(): void
    {
        $config = new VortosAwsSesConfig();
        $config->suppression()->onSuppressed('ignore');

        $this->expectException(InvalidConfigurationException::class);
        $this->resolve($config);
    }
}
