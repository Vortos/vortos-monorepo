<?php

declare(strict_types=1);

namespace Vortos\Tests\Messaging;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Vortos\Messaging\Contract\ProducerInterface;
use Vortos\Messaging\DependencyInjection\MessagingExtension;
use Vortos\Messaging\Driver\Kafka\Runtime\LazyKafkaProducer;

final class MessagingExtensionEnvDefaultsTest extends TestCase
{
    private ?string $previousDriver;

    protected function setUp(): void
    {
        $this->previousDriver = $_ENV['VORTOS_MESSAGING_DRIVER'] ?? null;
    }

    protected function tearDown(): void
    {
        if ($this->previousDriver === null) {
            unset($_ENV['VORTOS_MESSAGING_DRIVER']);
            return;
        }

        $_ENV['VORTOS_MESSAGING_DRIVER'] = $this->previousDriver;
    }

    public function test_extension_uses_env_defaults_without_project_messaging_config(): void
    {
        $_ENV['VORTOS_MESSAGING_DRIVER'] = 'kafka';

        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', sys_get_temp_dir() . '/missing_vortos_messaging_config');
        $container->setParameter('kernel.env', 'dev');

        (new MessagingExtension())->load([], $container);

        $this->assertSame(LazyKafkaProducer::class, (string) $container->getAlias(ProducerInterface::class));
    }
}
