<?php

declare(strict_types=1);

namespace Vortos\Tests\Ses;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Vortos\Ses\Contract\MailerInterface;
use Vortos\Ses\DependencyInjection\SesExtension;
use Vortos\Ses\Driver\Log\LogMailer;
use Vortos\Ses\Driver\Null\NullMailer;
use Vortos\Ses\Middleware\EmailMiddlewareStack;
use Vortos\Ses\Outbox\OutboxMailer;
use Vortos\Tracing\NoOpTracer;
use Vortos\Tracing\Contract\TracingInterface;

final class SesExtensionDriverRegistrationTest extends TestCase
{
    private function buildContainer(string $driver): ContainerBuilder
    {
        $prev = $_ENV['VORTOS_MAILER_DRIVER'] ?? null;
        $_ENV['VORTOS_MAILER_DRIVER'] = $driver;

        try {
            $container = new ContainerBuilder();
            $container->setParameter('kernel.project_dir', sys_get_temp_dir() . '/ses_no_config_' . uniqid());
            $container->setParameter('kernel.env', 'test');

            $container->register(\Psr\Log\LoggerInterface::class, NullLogger::class)->setPublic(false);
            $container->register(TracingInterface::class, NoOpTracer::class)->setPublic(false);

            (new SesExtension())->load([], $container);

            return $container;
        } finally {
            if ($prev === null) {
                unset($_ENV['VORTOS_MAILER_DRIVER']);
            } else {
                $_ENV['VORTOS_MAILER_DRIVER'] = $prev;
            }
        }
    }

    public function test_mailer_interface_points_to_outbox_mailer_when_outbox_enabled(): void
    {
        // Default config has outbox.enabled=true — MailerInterface → OutboxMailer
        $container = $this->buildContainer('null');
        $alias = (string) $container->getAlias(MailerInterface::class);
        $this->assertSame(OutboxMailer::class, $alias);
    }

    public function test_sending_mailer_alias_always_points_to_middleware_stack(): void
    {
        $container = $this->buildContainer('null');
        $alias = (string) $container->getAlias('vortos_ses.sending_mailer');
        $this->assertSame(EmailMiddlewareStack::class, $alias);
    }

    public function test_null_driver_sets_driver_alias_to_null_mailer(): void
    {
        $container = $this->buildContainer('null');
        $alias = (string) $container->getAlias('vortos_ses.driver');
        $this->assertSame(NullMailer::class, $alias);
    }

    public function test_log_driver_sets_driver_alias_to_log_mailer(): void
    {
        $container = $this->buildContainer('log');
        $alias = (string) $container->getAlias('vortos_ses.driver');
        $this->assertSame(LogMailer::class, $alias);
    }

    public function test_unknown_driver_falls_back_to_null_mailer(): void
    {
        $container = $this->buildContainer('unknown-driver');
        $alias = (string) $container->getAlias('vortos_ses.driver');
        $this->assertSame(NullMailer::class, $alias);
    }

    public function test_null_mailer_always_registered(): void
    {
        $container = $this->buildContainer('log');
        $this->assertTrue($container->hasDefinition(NullMailer::class));
    }

    public function test_log_mailer_always_registered(): void
    {
        $container = $this->buildContainer('null');
        $this->assertTrue($container->hasDefinition(LogMailer::class));
    }

    public function test_middleware_stack_always_registered(): void
    {
        $container = $this->buildContainer('null');
        $this->assertTrue($container->hasDefinition(EmailMiddlewareStack::class));
    }

    public function test_ses_client_not_registered_for_log_driver(): void
    {
        $container = $this->buildContainer('log');
        $this->assertFalse($container->hasDefinition(\Aws\SesV2\SesV2Client::class));
    }

    public function test_ses_client_registered_for_ses_driver(): void
    {
        $container = $this->buildContainer('ses');
        $this->assertTrue($container->hasDefinition(\Aws\SesV2\SesV2Client::class));
    }

    public function test_ses_health_check_registered_for_ses_driver(): void
    {
        $container = $this->buildContainer('ses');
        $this->assertTrue($container->hasDefinition(\Vortos\Ses\Driver\Ses\Health\SesHealthCheck::class));
    }

    public function test_ses_health_check_not_registered_for_log_driver(): void
    {
        $container = $this->buildContainer('log');
        $this->assertFalse($container->hasDefinition(\Vortos\Ses\Driver\Ses\Health\SesHealthCheck::class));
    }
}
