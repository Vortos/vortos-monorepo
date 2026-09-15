<?php

declare(strict_types=1);

namespace Vortos\Messaging\Tests\Driver\Kafka;

use PHPUnit\Framework\TestCase;
use Vortos\Messaging\Driver\Kafka\Runtime\ConsumerInstanceId;

final class ConsumerInstanceIdTest extends TestCase
{
    public function test_the_same_slot_and_consumer_always_give_the_same_member(): void
    {
        self::assertSame(
            ConsumerInstanceId::for('worker-blue.consumers-payments.00', 'registration.payment.completed'),
            ConsumerInstanceId::for('worker-blue.consumers-payments.00', 'registration.payment.completed'),
        );
    }

    public function test_each_consumer_in_a_shared_process_is_its_own_member(): void
    {
        self::assertNotSame(
            ConsumerInstanceId::for('host.consumers-identity.00', 'identity.invitation.sent'),
            ConsumerInstanceId::for('host.consumers-identity.00', 'identity.invitation.resent'),
        );
    }

    public function test_replicas_of_one_program_are_different_members(): void
    {
        self::assertNotSame(
            ConsumerInstanceId::for('host.consumers-registration.00', 'registration.submitted'),
            ConsumerInstanceId::for('host.consumers-registration.01', 'registration.submitted'),
        );
    }

    public function test_characters_kafka_refuses_are_replaced(): void
    {
        self::assertSame('a_b_c.identity.support-session.v1', ConsumerInstanceId::for('a b/c', 'identity.support-session.v1'));
    }

    public function test_an_over_long_id_is_cut_to_kafkas_limit_without_colliding(): void
    {
        $first  = ConsumerInstanceId::for(str_repeat('x', 300) . 'A', 'consumer');
        $second = ConsumerInstanceId::for(str_repeat('x', 300) . 'B', 'consumer');

        self::assertLessThanOrEqual(249, strlen($first));
        self::assertNotSame($first, $second);
    }

    public function test_an_empty_slot_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ConsumerInstanceId::for('  ', 'consumer');
    }
}
