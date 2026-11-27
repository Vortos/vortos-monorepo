<?php

declare(strict_types=1);

namespace Vortos\Messaging\Tests\Driver\Kafka\Runtime;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Every Kafka client takes its connection from KafkaClientConfig. A client that built its own \RdKafka\Conf
 * would read the DSN and the sign-in its own way, and against a broker that requires sign-in it would fail
 * (or, against one that still has an open listener, connect around it). That is how the producer, consumer,
 * health check and tail command had drifted apart before.
 */
final class KafkaClientConfigSingleSourceTest extends TestCase
{
    public function test_only_kafka_client_config_constructs_an_rdkafka_conf(): void
    {
        $root = dirname(__DIR__, 5); // packages/Vortos/src
        $offenders = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            $path = $file->getPathname();
            if (!str_ends_with($path, '.php') || str_contains($path, '/Tests/') || str_ends_with($path, '/Kafka/Runtime/KafkaClientConfig.php')) {
                continue;
            }
            if (preg_match('/new\s+\\\\?RdKafka\\\\Conf\b|use\s+RdKafka\\\\Conf\b/', (string) file_get_contents($path)) === 1) {
                $offenders[] = substr($path, strlen($root) + 1);
            }
        }

        self::assertSame([], $offenders, 'build Kafka client settings with KafkaClientConfig::conf()');
    }
}
