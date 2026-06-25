<?php

declare(strict_types=1);

namespace Vortos\Analytics\Tests\Unit\Privacy;

use PHPUnit\Framework\TestCase;
use Vortos\Analytics\Privacy\PiiRedactor;

final class PiiRedactorTest extends TestCase
{
    public function test_email_is_redacted(): void
    {
        $redactor = new PiiRedactor('salt');
        $result = $redactor->redact(['contact' => 'jane.doe@example.com']);

        $this->assertStringStartsWith('sha256:', $result['contact']);
    }

    public function test_phone_shaped_value_is_redacted(): void
    {
        $redactor = new PiiRedactor('salt');
        $result = $redactor->redact(['phone' => '+1-555-123-4567']);

        $this->assertStringStartsWith('sha256:', $result['phone']);
    }

    public function test_credit_card_shaped_value_is_redacted(): void
    {
        $redactor = new PiiRedactor('salt');
        $result = $redactor->redact(['card' => '4111 1111 1111 1111']);

        $this->assertStringStartsWith('sha256:', $result['card']);
    }

    public function test_non_pii_value_is_untouched(): void
    {
        $redactor = new PiiRedactor('salt');
        $result = $redactor->redact(['plan' => 'pro']);

        $this->assertSame('pro', $result['plan']);
    }

    public function test_short_numeric_value_is_not_treated_as_credit_card(): void
    {
        $redactor = new PiiRedactor('salt');
        $result = $redactor->redact(['seats' => '10']);

        $this->assertSame('10', $result['seats']);
    }

    public function test_hash_is_deterministic_for_same_salt_and_value(): void
    {
        $redactor = new PiiRedactor('fixed-salt');
        $a = $redactor->redact(['email' => 'a@b.com']);
        $b = $redactor->redact(['email' => 'a@b.com']);

        $this->assertSame($a['email'], $b['email']);
    }

    public function test_hash_differs_for_different_salts(): void
    {
        $a = (new PiiRedactor('salt-1'))->redact(['email' => 'a@b.com']);
        $b = (new PiiRedactor('salt-2'))->redact(['email' => 'a@b.com']);

        $this->assertNotSame($a['email'], $b['email']);
    }

    public function test_raw_allowed_key_bypasses_redaction(): void
    {
        $redactor = new PiiRedactor('salt', ['support_email']);
        $result = $redactor->redact(['support_email' => 'help@example.com', 'contact' => 'jane@example.com']);

        $this->assertSame('help@example.com', $result['support_email']);
        $this->assertStringStartsWith('sha256:', $result['contact']);
    }

    public function test_nested_array_values_are_recursively_redacted(): void
    {
        $redactor = new PiiRedactor('salt');
        $result = $redactor->redact(['nested' => ['email' => 'a@b.com', 'plan' => 'pro']]);

        $this->assertStringStartsWith('sha256:', $result['nested']['email']);
        $this->assertSame('pro', $result['nested']['plan']);
    }

    public function test_non_string_scalar_values_pass_through(): void
    {
        $redactor = new PiiRedactor('salt');
        $result = $redactor->redact(['seats' => 10, 'active' => true, 'ratio' => 1.5, 'nothing' => null]);

        $this->assertSame(['seats' => 10, 'active' => true, 'ratio' => 1.5, 'nothing' => null], $result);
    }

    public function test_empty_string_value_passes_through(): void
    {
        $redactor = new PiiRedactor('salt');
        $this->assertSame('', $redactor->redact(['note' => ''])['note']);
    }

    /** Corpus: a mix of obvious PII and obvious non-PII, every value classified correctly. */
    public function test_corpus_of_pii_and_non_pii_values(): void
    {
        $redactor = new PiiRedactor('salt');

        $piiCorpus = [
            'user@example.com',
            'first.last+tag@sub.example.co.uk',
            '+44 20 7946 0958',
            '555-123-4567',
            '4242424242424242',
            '4242-4242-4242-4242',
        ];
        foreach ($piiCorpus as $value) {
            $redacted = $redactor->redact(['v' => $value]);
            $this->assertStringStartsWith('sha256:', $redacted['v'], "Expected '{$value}' to be classified as PII.");
        }

        $nonPiiCorpus = [
            'pro',
            'enterprise-plan',
            'US',
            'dark-mode',
            '42',
            'true',
        ];
        foreach ($nonPiiCorpus as $value) {
            $redacted = $redactor->redact(['v' => $value]);
            $this->assertSame($value, $redacted['v'], "Expected '{$value}' to be classified as non-PII.");
        }
    }
}
