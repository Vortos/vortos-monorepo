<?php

declare(strict_types=1);

namespace Vortos\Secrets\Tests\Unit\Value;

use PHPUnit\Framework\TestCase;
use Vortos\Secrets\Exception\SecretAlreadyWipedException;
use Vortos\Secrets\Value\SecretValue;

final class SecretValueTest extends TestCase
{
    public function test_reveal_returns_the_plaintext(): void
    {
        $secret = SecretValue::fromString('s3cr3t-value');

        self::assertSame('s3cr3t-value', $secret->reveal());
    }

    public function test_to_string_is_redacted(): void
    {
        $secret = SecretValue::fromString('s3cr3t-value');

        self::assertSame('***', (string) $secret);
        self::assertSame('***', sprintf('%s', $secret));
    }

    public function test_debug_info_is_redacted(): void
    {
        $secret = SecretValue::fromString('s3cr3t-value');

        self::assertSame(['value' => '***'], $secret->__debugInfo());
    }

    public function test_var_dump_output_is_redacted(): void
    {
        $secret = SecretValue::fromString('s3cr3t-value');

        ob_start();
        var_dump($secret);
        $output = (string) ob_get_clean();

        self::assertStringNotContainsString('s3cr3t-value', $output);
        self::assertStringContainsString('***', $output);
    }

    public function test_var_export_output_is_redacted(): void
    {
        $secret = SecretValue::fromString('s3cr3t-value');

        $output = var_export($secret, true);

        self::assertStringNotContainsString('s3cr3t-value', $output);
    }

    public function test_print_r_output_is_redacted(): void
    {
        $secret = SecretValue::fromString('s3cr3t-value');

        $output = print_r($secret, true);

        self::assertStringNotContainsString('s3cr3t-value', $output);
    }

    public function test_json_encode_is_redacted(): void
    {
        $secret = SecretValue::fromString('s3cr3t-value');

        $json = json_encode(['secret' => $secret]);

        self::assertIsString($json);
        self::assertStringNotContainsString('s3cr3t-value', $json);
        self::assertStringContainsString('***', $json);
    }

    public function test_exception_interpolation_is_redacted(): void
    {
        $secret = SecretValue::fromString('s3cr3t-value');

        try {
            throw new \RuntimeException("failed with value: {$secret}");
        } catch (\RuntimeException $e) {
            self::assertStringNotContainsString('s3cr3t-value', $e->getMessage());
        }
    }

    public function test_serialize_throws(): void
    {
        $secret = SecretValue::fromString('s3cr3t-value');

        $this->expectException(\LogicException::class);
        serialize($secret);
    }

    public function test_equals_is_true_for_same_plaintext(): void
    {
        $a = SecretValue::fromString('same');
        $b = SecretValue::fromString('same');

        self::assertTrue($a->equals($b));
    }

    public function test_equals_is_false_for_different_plaintext(): void
    {
        $a = SecretValue::fromString('one');
        $b = SecretValue::fromString('two');

        self::assertFalse($a->equals($b));
    }

    public function test_wipe_then_reveal_throws(): void
    {
        $secret = SecretValue::fromString('s3cr3t-value');
        $secret->wipe();

        $this->expectException(SecretAlreadyWipedException::class);
        $secret->reveal();
    }

    public function test_wipe_then_equals_throws(): void
    {
        $secret = SecretValue::fromString('s3cr3t-value');
        $other = SecretValue::fromString('s3cr3t-value');
        $secret->wipe();

        $this->expectException(SecretAlreadyWipedException::class);
        $secret->equals($other);
    }

    public function test_wipe_is_idempotent(): void
    {
        $secret = SecretValue::fromString('s3cr3t-value');
        $secret->wipe();
        $secret->wipe();

        self::assertTrue($secret->isWiped());
    }

    public function test_wipe_does_not_affect_other_instances(): void
    {
        $a = SecretValue::fromString('value-a');
        $b = SecretValue::fromString('value-b');

        $a->wipe();

        self::assertFalse($b->isWiped());
        self::assertSame('value-b', $b->reveal());
    }

    public function test_class_is_final(): void
    {
        $reflection = new \ReflectionClass(SecretValue::class);

        self::assertTrue($reflection->isFinal());
    }

    public function test_empty_string_secret_round_trips(): void
    {
        $secret = SecretValue::fromString('');

        self::assertSame('', $secret->reveal());
    }
}
