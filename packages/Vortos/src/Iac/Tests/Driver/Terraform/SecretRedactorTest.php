<?php

declare(strict_types=1);

namespace Vortos\Iac\Tests\Driver\Terraform;

use PHPUnit\Framework\TestCase;
use Vortos\Iac\Driver\Terraform\SecretRedactor;

final class SecretRedactorTest extends TestCase
{
    public function test_masks_single_secret(): void
    {
        $redactor = new SecretRedactor(['my-secret-token']);
        $this->assertSame('Authorization: ***', $redactor->redact('Authorization: my-secret-token'));
    }

    public function test_masks_multiple_secrets(): void
    {
        $redactor = new SecretRedactor(['aaaa', 'bbbb']);
        $this->assertSame('x=*** y=***', $redactor->redact('x=aaaa y=bbbb'));
    }

    public function test_masks_partial_substring_occurrence(): void
    {
        $redactor = new SecretRedactor(['secret']);
        $this->assertSame('my_***_value and another_***_here', $redactor->redact('my_secret_value and another_secret_here'));
    }

    public function test_masks_multiline_output(): void
    {
        $redactor = new SecretRedactor(['my-token']);
        $input = "line1: my-token\nline2: safe\nline3: my-token again";
        $expected = "line1: ***\nline2: safe\nline3: *** again";
        $this->assertSame($expected, $redactor->redact($input));
    }

    public function test_short_secrets_below_min_length_are_ignored(): void
    {
        $redactor = new SecretRedactor(['ab', 'abc', '']);
        $this->assertSame('ab abc', $redactor->redact('ab abc'));
    }

    public function test_secrets_exactly_at_min_length_are_redacted(): void
    {
        $redactor = new SecretRedactor(['abcd']);
        $this->assertSame('x=***', $redactor->redact('x=abcd'));
    }

    public function test_empty_input_returns_empty(): void
    {
        $redactor = new SecretRedactor(['my-secret']);
        $this->assertSame('', $redactor->redact(''));
    }

    public function test_no_secrets_returns_input_unchanged(): void
    {
        $redactor = new SecretRedactor([]);
        $this->assertSame('hello world', $redactor->redact('hello world'));
    }

    public function test_longest_match_first_prevents_partial_redaction(): void
    {
        $redactor = new SecretRedactor(['token', 'my-secret-token']);
        $result = $redactor->redact('value: my-secret-token');
        $this->assertSame('value: ***', $result);
    }

    public function test_overlapping_secrets_longest_wins(): void
    {
        $redactor = new SecretRedactor(['abcdef', 'abcd']);
        $this->assertSame('***', $redactor->redact('abcdef'));
    }

    public function test_secret_not_present_in_input_is_noop(): void
    {
        $redactor = new SecretRedactor(['not-present-at-all']);
        $this->assertSame('clean output', $redactor->redact('clean output'));
    }
}
