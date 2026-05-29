<?php

declare(strict_types=1);

namespace Vortos\Tests\Paddle\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Vortos\Paddle\DependencyInjection\PaddleExtension;
use Vortos\Paddle\Webhook\PaddleWebhookController;
use Vortos\Paddle\Webhook\PaddleWebhookDispatcher;
use Vortos\Paddle\Webhook\WebhookEventFactory;
use Vortos\Paddle\Webhook\WebhookIdempotencyStore;
use Vortos\Paddle\Webhook\WebhookIpGuard;
use Vortos\Paddle\Webhook\WebhookVerifier;
use Vortos\Paddle\Webhook\WebhookVerifierInterface;

final class PaddleExtensionDefaultsTest extends TestCase
{
    private ContainerBuilder $container;

    protected function setUp(): void
    {
        $this->container = new ContainerBuilder();
        $this->container->setParameter('kernel.project_dir', sys_get_temp_dir() . '/paddle_no_config_' . uniqid());
        $this->container->setParameter('kernel.env', 'test');

        (new PaddleExtension())->load([], $this->container);
    }

    public function test_alias_is_vortos_paddle(): void
    {
        $this->assertSame('vortos_paddle', (new PaddleExtension())->getAlias());
    }

    public function test_mode_defaults_to_sandbox(): void
    {
        $this->assertSame('sandbox', $this->container->getParameter('vortos_paddle.mode'));
    }

    public function test_webhook_path_defaults(): void
    {
        $this->assertSame('/webhooks/paddle', $this->container->getParameter('vortos_paddle.webhook_path'));
    }

    public function test_webhooks_enabled_by_default(): void
    {
        $this->assertTrue($this->container->getParameter('vortos_paddle.webhooks.enabled'));
    }

    public function test_idempotency_table_defaults(): void
    {
        $this->assertSame(
            'paddle_webhook_idempotency',
            $this->container->getParameter('vortos_paddle.webhooks.idempotency_table'),
        );
    }

    public function test_idempotency_ttl_defaults_to_72_hours(): void
    {
        $this->assertSame(259200, $this->container->getParameter('vortos_paddle.webhooks.idempotency_ttl_seconds'));
    }

    public function test_ip_allowlist_disabled_by_default(): void
    {
        $this->assertFalse($this->container->getParameter('vortos_paddle.security.enforce_ip_allowlist'));
    }

    public function test_replay_window_defaults_to_5_seconds(): void
    {
        $this->assertSame(5, $this->container->getParameter('vortos_paddle.security.replay_window_seconds'));
    }

    public function test_sandbox_ips_disabled_by_default(): void
    {
        $this->assertFalse($this->container->getParameter('vortos_paddle.security.allow_sandbox_ips'));
    }

    public function test_webhook_services_are_registered(): void
    {
        $this->assertTrue($this->container->hasDefinition(WebhookVerifier::class));
        $this->assertTrue($this->container->hasDefinition(WebhookIpGuard::class));
        $this->assertTrue($this->container->hasDefinition(WebhookEventFactory::class));
        $this->assertTrue($this->container->hasDefinition(WebhookIdempotencyStore::class));
        $this->assertTrue($this->container->hasDefinition(PaddleWebhookDispatcher::class));
        $this->assertTrue($this->container->hasDefinition(PaddleWebhookController::class));
    }

    public function test_verifier_interface_aliases_to_webhook_verifier(): void
    {
        $this->assertSame(WebhookVerifier::class, (string) $this->container->getAlias(WebhookVerifierInterface::class));
    }

    public function test_observability_defaults_to_enabled(): void
    {
        $this->assertTrue($this->container->getParameter('vortos_paddle.observability.logging'));
        $this->assertTrue($this->container->getParameter('vortos_paddle.observability.tracing'));
        $this->assertTrue($this->container->getParameter('vortos_paddle.observability.metrics'));
    }
}
