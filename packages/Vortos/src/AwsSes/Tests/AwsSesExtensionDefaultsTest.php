<?php

declare(strict_types=1);

namespace Vortos\AwsSes\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Vortos\AwsSes\DependencyInjection\AwsSesExtension;
use Vortos\AwsSes\Webhook\SnsWebhookController;

final class AwsSesExtensionDefaultsTest extends TestCase
{
    private ContainerBuilder $container;

    protected function setUp(): void
    {
        $this->container = new ContainerBuilder();
        $this->container->setParameter('kernel.project_dir', sys_get_temp_dir() . '/ses_no_config_' . uniqid());
        $this->container->setParameter('kernel.env', 'test');

        (new AwsSesExtension())->load([], $this->container);
    }

    public function test_driver_defaults_to_log(): void
    {
        $this->assertSame('log', $this->container->getParameter('vortos_aws_ses.driver'));
    }

    public function test_region_defaults_to_us_east_1(): void
    {
        $this->assertSame('us-east-1', $this->container->getParameter('vortos_aws_ses.region'));
    }

    public function test_fallback_region_defaults_to_null(): void
    {
        $this->assertNull($this->container->getParameter('vortos_aws_ses.fallback_region'));
    }

    public function test_default_from_address_defaults_to_empty_string(): void
    {
        $this->assertSame('', $this->container->getParameter('vortos_aws_ses.default_from_address'));
    }

    public function test_reply_to_defaults_to_null(): void
    {
        $this->assertNull($this->container->getParameter('vortos_aws_ses.reply_to'));
    }

    public function test_configuration_set_defaults_to_null(): void
    {
        $this->assertNull($this->container->getParameter('vortos_aws_ses.configuration_set'));
    }

    public function test_aws_client_endpoint_override_defaults_to_null(): void
    {
        $this->assertNull($this->container->getParameter('vortos_aws_ses.aws_client.endpoint_override'));
    }

    public function test_aws_client_http_timeout_defaults_to_2(): void
    {
        $this->assertSame(2.0, $this->container->getParameter('vortos_aws_ses.aws_client.http_timeout'));
    }

    public function test_aws_client_max_retries_defaults_to_3(): void
    {
        $this->assertSame(3, $this->container->getParameter('vortos_aws_ses.aws_client.max_retries'));
    }

    public function test_outbox_enabled_defaults_to_true(): void
    {
        $this->assertTrue($this->container->getParameter('vortos_aws_ses.outbox.enabled'));
    }

    public function test_outbox_table_name_defaults_to_vortos_ses_outbox(): void
    {
        $this->assertSame('vortos_aws_ses_outbox', $this->container->getParameter('vortos_aws_ses.outbox.table_name'));
    }

    public function test_outbox_batch_size_defaults_to_50(): void
    {
        $this->assertSame(50, $this->container->getParameter('vortos_aws_ses.outbox.batch_size'));
    }

    public function test_outbox_sleep_seconds_when_empty_defaults_to_2(): void
    {
        $this->assertSame(2, $this->container->getParameter('vortos_aws_ses.outbox.sleep_seconds_when_empty'));
    }

    public function test_outbox_max_delivery_attempts_defaults_to_5(): void
    {
        $this->assertSame(5, $this->container->getParameter('vortos_aws_ses.outbox.max_delivery_attempts'));
    }

    public function test_outbox_retry_strategy_defaults_to_exponential(): void
    {
        $this->assertSame('exponential', $this->container->getParameter('vortos_aws_ses.outbox.retry_strategy'));
    }

    public function test_outbox_backoff_base_defaults_to_30(): void
    {
        $this->assertSame(30, $this->container->getParameter('vortos_aws_ses.outbox.backoff_base_seconds'));
    }

    public function test_outbox_backoff_cap_defaults_to_3600(): void
    {
        $this->assertSame(3600, $this->container->getParameter('vortos_aws_ses.outbox.backoff_cap_seconds'));
    }

    public function test_outbox_stale_message_timeout_defaults_to_300(): void
    {
        $this->assertSame(300, $this->container->getParameter('vortos_aws_ses.outbox.stale_message_timeout_seconds'));
    }

    public function test_webhooks_enabled_defaults_to_true(): void
    {
        $this->assertTrue($this->container->getParameter('vortos_aws_ses.webhooks.enabled'));
    }

    public function test_webhooks_route_path_defaults(): void
    {
        $this->assertSame('/webhooks/aws/ses', $this->container->getParameter('vortos_aws_ses.webhooks.route_path'));
    }

    public function test_suppression_table_name_defaults(): void
    {
        $this->assertSame('vortos_aws_ses_suppression_list', $this->container->getParameter('vortos_aws_ses.suppression.table_name'));
    }

    public function test_suppression_sync_on_startup_defaults_to_false(): void
    {
        $this->assertFalse($this->container->getParameter('vortos_aws_ses.suppression.sync_on_startup'));
    }

    public function test_suppression_on_suppressed_defaults_to_throw(): void
    {
        $this->assertSame('throw', $this->container->getParameter('vortos_aws_ses.suppression.on_suppressed'));
    }

    public function test_rate_limit_max_send_rate_defaults_to_14(): void
    {
        $this->assertSame(14, $this->container->getParameter('vortos_aws_ses.rate_limit.max_send_rate'));
    }

    public function test_rate_limit_burst_defaults_to_14(): void
    {
        $this->assertSame(14, $this->container->getParameter('vortos_aws_ses.rate_limit.burst'));
    }

    public function test_rate_limit_wait_timeout_ms_defaults_to_5000(): void
    {
        $this->assertSame(5000, $this->container->getParameter('vortos_aws_ses.rate_limit.wait_timeout_ms'));
    }

    public function test_audit_log_enabled_defaults_to_true(): void
    {
        $this->assertTrue($this->container->getParameter('vortos_aws_ses.audit_log.enabled'));
    }

    public function test_audit_log_table_name_defaults(): void
    {
        $this->assertSame('vortos_aws_ses_audit_log', $this->container->getParameter('vortos_aws_ses.audit_log.table_name'));
    }

    public function test_circuit_breaker_failure_threshold_defaults_to_5(): void
    {
        $this->assertSame(5, $this->container->getParameter('vortos_aws_ses.circuit_breaker.failure_threshold'));
    }

    public function test_circuit_breaker_reset_timeout_defaults_to_60(): void
    {
        $this->assertSame(60, $this->container->getParameter('vortos_aws_ses.circuit_breaker.reset_timeout_seconds'));
    }

    public function test_observability_defaults_to_enabled(): void
    {
        $this->assertTrue($this->container->getParameter('vortos_aws_ses.observability.logging'));
        $this->assertTrue($this->container->getParameter('vortos_aws_ses.observability.tracing'));
        $this->assertTrue($this->container->getParameter('vortos_aws_ses.observability.metrics'));
        $this->assertSame([], $this->container->getParameter('vortos_aws_ses.observability.logging_disabled_for'));
        $this->assertSame([], $this->container->getParameter('vortos_aws_ses.observability.tracing_disabled_for'));
        $this->assertSame([], $this->container->getParameter('vortos_aws_ses.observability.metrics_disabled_for'));
    }

    public function test_alias_is_vortos_aws_ses(): void
    {
        $this->assertSame('vortos_aws_ses', (new AwsSesExtension())->getAlias());
    }

    public function test_sns_webhook_controller_is_tagged_and_public(): void
    {
        $definition = $this->container->getDefinition(SnsWebhookController::class);

        $this->assertTrue($definition->isPublic(), 'SnsWebhookController must be public so the kernel can resolve it at request time');
        $this->assertNotEmpty($definition->getTag('vortos.api.controller'), 'SnsWebhookController must carry vortos.api.controller so RouteCompilerPass registers its route');
    }
}
