<?php

declare(strict_types=1);

namespace Vortos\Paddle\Tests\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Vortos\Paddle\DependencyInjection\VortosPaddleConfig;

final class VortosPaddleConfigTest extends TestCase
{
    public function test_defaults_are_safe(): void
    {
        $config = new VortosPaddleConfig();
        $array  = $config->toArray();

        $this->assertSame('sandbox', $array['mode']);
        $this->assertSame('/webhooks/paddle', $array['webhook_path']);
        $this->assertFalse($array['security']['enforce_ip_allowlist']);
        $this->assertSame(5, $array['security']['replay_window_seconds']);
        $this->assertFalse($array['security']['allow_sandbox_ips']);
        $this->assertTrue($array['webhooks']['enabled']);
        $this->assertSame('paddle_webhook_inbox', $array['webhooks']['inbox_table']);
        $this->assertSame(50, $array['webhooks']['inbox_batch_size']);
        $this->assertSame(5, $array['webhooks']['inbox_max_attempts']);
        $this->assertSame(60, $array['webhooks']['backoff_base_seconds']);
        $this->assertSame(3600, $array['webhooks']['backoff_cap_seconds']);
        $this->assertTrue($array['observability']['logging']);
        $this->assertTrue($array['observability']['tracing']);
        $this->assertTrue($array['observability']['metrics']);
    }

    public function test_fluent_setters_update_values(): void
    {
        $config = new VortosPaddleConfig();
        $config
            ->mode('live')
            ->apiKey('test_api_key')
            ->notificationSecret('test_secret')
            ->webhookPath('/hooks/paddle');

        $config->security()
            ->enforceIpAllowlist(true)
            ->replayWindowSeconds(10)
            ->allowSandboxIps(true);

        $config->webhooks()
            ->enabled(false)
            ->inboxTable('custom_inbox')
            ->inboxMaxAttempts(7);

        $array = $config->toArray();

        $this->assertSame('live', $array['mode']);
        $this->assertSame('test_api_key', $array['api_key']);
        $this->assertSame('test_secret', $array['notification_secret']);
        $this->assertSame('/hooks/paddle', $array['webhook_path']);
        $this->assertTrue($array['security']['enforce_ip_allowlist']);
        $this->assertSame(10, $array['security']['replay_window_seconds']);
        $this->assertTrue($array['security']['allow_sandbox_ips']);
        $this->assertFalse($array['webhooks']['enabled']);
        $this->assertSame('custom_inbox', $array['webhooks']['inbox_table']);
        $this->assertSame(7, $array['webhooks']['inbox_max_attempts']);
    }

    public function test_observability_section_is_configurable(): void
    {
        $config = new VortosPaddleConfig();
        $config->observability()
            ->logging(false)
            ->tracing(false)
            ->metrics(false);

        $array = $config->toArray();

        $this->assertFalse($array['observability']['logging']);
        $this->assertFalse($array['observability']['tracing']);
        $this->assertFalse($array['observability']['metrics']);
    }
}
