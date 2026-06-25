<?php
declare(strict_types=1);

namespace Vortos\Auth\Tests\Lockout;

use PHPUnit\Framework\TestCase;
use Vortos\Auth\Lockout\LockoutKeyNormalizer;

final class LockoutKeyNormalizerTest extends TestCase
{
    private LockoutKeyNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new LockoutKeyNormalizer();
    }

    public function test_email_case_variants_normalize_to_same_key(): void
    {
        $a = $this->normalizer->normalize('email', 'Victim@Example.COM');
        $b = $this->normalizer->normalize('email', 'victim@example.com');
        $c = $this->normalizer->normalize('email', 'VICTIM@EXAMPLE.COM');

        $this->assertSame($a, $b);
        $this->assertSame($b, $c);
    }

    public function test_email_with_leading_trailing_whitespace_normalizes(): void
    {
        $a = $this->normalizer->normalize('email', '  user@example.com  ');
        $b = $this->normalizer->normalize('email', 'user@example.com');

        $this->assertSame($a, $b);
    }

    public function test_ip_values_are_not_lowercased(): void
    {
        $a = $this->normalizer->normalize('ip', '10.0.0.1');
        $b = $this->normalizer->normalize('ip', '10.0.0.1');

        $this->assertSame($a, $b);
    }

    public function test_ip_whitespace_is_trimmed(): void
    {
        $a = $this->normalizer->normalize('ip', ' 10.0.0.1 ');
        $b = $this->normalizer->normalize('ip', '10.0.0.1');

        $this->assertSame($a, $b);
    }

    public function test_output_is_sha256_hash(): void
    {
        $result = $this->normalizer->normalize('email', 'user@example.com');

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $result);
    }

    public function test_different_types_produce_different_keys(): void
    {
        $a = $this->normalizer->normalize('email', 'value');
        $b = $this->normalizer->normalize('ip', 'value');

        $this->assertNotSame($a, $b);
    }

    public function test_different_emails_produce_different_keys(): void
    {
        $a = $this->normalizer->normalize('email', 'alice@example.com');
        $b = $this->normalizer->normalize('email', 'bob@example.com');

        $this->assertNotSame($a, $b);
    }

    public function test_unicode_email_normalized_correctly(): void
    {
        $a = $this->normalizer->normalize('email', 'Ünïcödé@example.com');
        $b = $this->normalizer->normalize('email', 'ünïcödé@example.com');

        $this->assertSame($a, $b);
    }
}
