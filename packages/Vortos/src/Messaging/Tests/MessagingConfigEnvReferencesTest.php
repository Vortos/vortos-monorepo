<?php

declare(strict_types=1);

namespace Vortos\Messaging\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Vortos\Foundation\Config\Env;
use Vortos\Messaging\Attribute\MessagingConfig;
use Vortos\Messaging\Attribute\RegisterTransport;
use Vortos\Messaging\DependencyInjection\Compiler\MessagingConfigCompilerPass;
use Vortos\Messaging\Definition\Transport\AbstractTransportDefinition;
use Vortos\Messaging\Driver\Kafka\Definition\KafkaTransportDefinition;
use Vortos\Messaging\Driver\Kafka\ValueObject\SaslConfig;

#[MessagingConfig]
final class EnvRefMessagingConfigFixture
{
    #[RegisterTransport]
    public function ordersTransport(): AbstractTransportDefinition
    {
        return KafkaTransportDefinition::create('orders.placed')
            ->dsn(Env::string('KAFKA_BROKERS'))
            ->topic('orders.placed')
            ->partitions(Env::int('KAFKA_PARTITIONS', default: 12))
            ->replicationFactor(Env::int('KAFKA_REPLICATION_FACTOR', default: 3))
            ->security(SaslConfig::scramSha256(Env::string('KAFKA_SASL_USER'), Env::string('KAFKA_SASL_PASS')));
    }
}

final class MessagingConfigEnvReferencesTest extends TestCase
{
    private function compile(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->register(EnvRefMessagingConfigFixture::class, EnvRefMessagingConfigFixture::class)
            ->addTag('vortos.messaging_config');

        (new MessagingConfigCompilerPass())->process($container);

        return $container;
    }

    public function test_env_references_become_placeholders(): void
    {
        $transports = $this->compile()->getParameter('vortos.transports');
        $transport  = $transports['orders.placed'];

        $this->assertSame('%env(KAFKA_BROKERS)%', $transport['dsn']);
        $this->assertSame(
            '%env(int:default:vortos.env_default.KAFKA_PARTITIONS:KAFKA_PARTITIONS)%',
            $transport['provisioning']['partitions'],
        );
        $this->assertSame(
            '%env(int:default:vortos.env_default.KAFKA_REPLICATION_FACTOR:KAFKA_REPLICATION_FACTOR)%',
            $transport['provisioning']['replication'],
        );
        $this->assertSame('%env(KAFKA_SASL_USER)%', $transport['security']['sasl']['username']);
        $this->assertSame('%env(KAFKA_SASL_PASS)%', $transport['security']['sasl']['password']);
    }

    public function test_defaults_are_registered_as_parameters(): void
    {
        $container = $this->compile();

        $this->assertSame(12, $container->getParameter('vortos.env_default.KAFKA_PARTITIONS'));
        $this->assertSame(3, $container->getParameter('vortos.env_default.KAFKA_REPLICATION_FACTOR'));
        $this->assertFalse($container->hasParameter('vortos.env_default.KAFKA_BROKERS'), 'No default declared — no parameter registered');
    }

    public function test_container_resolves_typed_env_at_runtime(): void
    {
        // End-to-end through Symfony's env placeholder machinery: the resolved
        // value reaching a service must be a typed int, from env when set and
        // from the declared default when not.
        $container = $this->compile();
        $container->register('test.transport_holder', \ArrayObject::class)
            ->setArguments([$container->getParameter('vortos.transports')])
            ->setPublic(true);

        putenv('KAFKA_BROKERS=kafka://broker-1:9092');
        $_ENV['KAFKA_BROKERS'] = 'kafka://broker-1:9092';
        putenv('KAFKA_PARTITIONS=24');
        $_ENV['KAFKA_PARTITIONS'] = '24';
        putenv('KAFKA_SASL_USER=svc');
        $_ENV['KAFKA_SASL_USER'] = 'svc';
        putenv('KAFKA_SASL_PASS=secret');
        $_ENV['KAFKA_SASL_PASS'] = 'secret';
        // KAFKA_REPLICATION_FACTOR intentionally NOT set → default kicks in

        try {
            $container->compile(true);
            $config = $container->get('test.transport_holder')->getArrayCopy()['orders.placed'];

            $this->assertSame('kafka://broker-1:9092', $config['dsn']);
            $this->assertSame(24, $config['provisioning']['partitions']);
            $this->assertSame(3, $config['provisioning']['replication'], 'Unset env var must fall back to the declared default');
            $this->assertSame('secret', $config['security']['sasl']['password']);
        } finally {
            foreach (['KAFKA_BROKERS', 'KAFKA_PARTITIONS', 'KAFKA_SASL_USER', 'KAFKA_SASL_PASS'] as $var) {
                putenv($var);
                unset($_ENV[$var]);
            }
        }
    }
}
