<?php

declare(strict_types=1);

namespace Vortos\Messaging\Tests\Driver\Kafka\Runtime;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Vortos\Messaging\Driver\Kafka\Runtime\KafkaClientConfig;

final class KafkaClientConfigTest extends TestCase
{
    public function test_a_plain_dsn_connects_anonymously(): void
    {
        self::assertSame(
            ['metadata.broker.list' => 'kafka:9092'],
            KafkaClientConfig::settings(['dsn' => 'kafka://kafka:9092'], 't'),
        );
    }

    public function test_several_brokers_and_a_trailing_slash(): void
    {
        self::assertSame(
            'b1:9092,b_2:9093',
            KafkaClientConfig::settings(['dsn' => 'kafka://b1:9092, b_2:9093/'], 't')['metadata.broker.list'],
        );
    }

    public function test_dsn_credentials_sign_in_with_scram_sha_512_by_default(): void
    {
        self::assertSame([
            'metadata.broker.list' => 'kafka:9094',
            'sasl.username' => 'app',
            'sasl.password' => 'p@ss:w/rd?&=',
            'sasl.mechanisms' => 'SCRAM-SHA-512',
            'security.protocol' => 'SASL_PLAINTEXT',
        ], KafkaClientConfig::settings(['dsn' => 'kafka://app:' . rawurlencode('p@ss:w/rd?&=') . '@kafka:9094'], 't'));
    }

    public function test_the_mechanism_can_be_chosen(): void
    {
        $s = KafkaClientConfig::settings(['dsn' => 'kafka://app:pw@kafka:9094?sasl_mechanism=SCRAM-SHA-256'], 't');
        self::assertSame('SCRAM-SHA-256', $s['sasl.mechanisms']);
    }

    public function test_the_security_block_still_signs_in(): void
    {
        $s = KafkaClientConfig::settings([
            'dsn' => 'kafka://kafka:9094',
            'security' => ['sasl' => ['mechanism' => 'SCRAM-SHA-256', 'username' => 'u', 'password' => 'p']],
        ], 't');
        self::assertSame(['u', 'p', 'SCRAM-SHA-256', 'SASL_PLAINTEXT'], [$s['sasl.username'], $s['sasl.password'], $s['sasl.mechanisms'], $s['security.protocol']]);
    }

    public function test_ssl_with_and_without_sasl(): void
    {
        $ca = tempnam(sys_get_temp_dir(), 'ca');
        try {
            $ssl = ['ca_location' => $ca, 'verify_peer' => true];
            $s = KafkaClientConfig::settings(['dsn' => 'kafka://k:9093', 'security' => ['ssl' => $ssl]], 't');
            self::assertSame(['SSL', $ca, 'true'], [$s['security.protocol'], $s['ssl.ca.location'], $s['enable.ssl.certificate.verification']]);

            $s = KafkaClientConfig::settings(['dsn' => 'kafka://u:p@k:9093?sasl_mechanism=PLAIN', 'security' => ['ssl' => $ssl]], 't');
            self::assertSame(['SASL_SSL', 'PLAIN'], [$s['security.protocol'], $s['sasl.mechanisms']]);
        } finally {
            unlink($ca);
        }
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function refused(): iterable
    {
        yield 'not kafka' => [['dsn' => 'redis://r:6379'], 'needs a kafka:// DSN'];
        yield 'empty' => [['dsn' => ''], 'needs a kafka:// DSN'];
        yield 'user without password' => [['dsn' => 'kafka://app@kafka:9094'], 'needs both a user and a password'];
        yield 'empty password' => [['dsn' => 'kafka://app:@kafka:9094'], 'needs both a user and a password'];
        yield 'no port' => [['dsn' => 'kafka://kafka'], 'is not a host:port broker'];
        yield 'unknown option' => [['dsn' => 'kafka://u:p@kafka:9094?sasl_mechanisms=PLAIN'], "unknown DSN option 'sasl_mechanisms'"];
        yield 'unknown mechanism' => [['dsn' => 'kafka://u:p@kafka:9094?sasl_mechanism=GSSAPI'], "unknown sasl_mechanism 'GSSAPI'"];
        yield 'mechanism without credentials' => [['dsn' => 'kafka://kafka:9094?sasl_mechanism=SCRAM-SHA-512'], 'sasl_mechanism without a user'];
        yield 'PLAIN in the clear' => [['dsn' => 'kafka://u:p@kafka:9094?sasl_mechanism=PLAIN'], 'without TLS'];
        yield 'PLAIN in the clear, security block' => [['dsn' => 'kafka://kafka:9094', 'security' => ['sasl' => ['mechanism' => 'PLAIN', 'username' => 'u', 'password' => 'p']]], 'without TLS'];
        yield 'both ways at once' => [['dsn' => 'kafka://u:p@kafka:9094', 'security' => ['sasl' => ['mechanism' => 'SCRAM-SHA-512', 'username' => 'u', 'password' => 'p']]], 'declare one'];
        yield 'empty security password' => [['dsn' => 'kafka://kafka:9094', 'security' => ['sasl' => ['mechanism' => 'SCRAM-SHA-512', 'username' => 'u', 'password' => '']]], 'must not be empty'];
        yield 'unreadable CA' => [['dsn' => 'kafka://kafka:9093', 'security' => ['ssl' => ['ca_location' => '/nonexistent/ca.pem']]], 'not readable'];
    }

    /** @param array<string, mixed> $transport */
    #[DataProvider('refused')]
    public function test_refused_and_never_quotes_the_password(array $transport, string $message): void
    {
        try {
            KafkaClientConfig::settings($transport, 't');
            self::fail('expected a refusal');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString($message, $e->getMessage());
            self::assertStringNotContainsString(':p@', $e->getMessage());
        }
    }

    public function test_a_bad_broker_message_masks_the_password(): void
    {
        try {
            KafkaClientConfig::settings(['dsn' => 'kafka://app:hunter2@kafka'], 't');
            self::fail('expected a refusal');
        } catch (InvalidArgumentException $e) {
            self::assertStringNotContainsString('hunter2', $e->getMessage());
            self::assertStringContainsString('kafka://app:***@kafka', $e->getMessage());
        }
    }

    public function test_brokers_drop_the_credentials(): void
    {
        self::assertSame('kafka:9094', KafkaClientConfig::brokers('kafka://app:pw@kafka:9094'));
    }

    public function test_redact(): void
    {
        self::assertSame('kafka://app:***@kafka:9094', KafkaClientConfig::redact('kafka://app:s3cr@t@kafka:9094'));
        self::assertSame('kafka://kafka:9092', KafkaClientConfig::redact('kafka://kafka:9092'));
        self::assertSame('failed to connect to kafka://app:***@kafka:9094: x', KafkaClientConfig::redact('failed to connect to kafka://app:pw@kafka:9094: x'));
    }
}
