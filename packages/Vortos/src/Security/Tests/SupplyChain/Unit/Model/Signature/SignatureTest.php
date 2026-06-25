<?php

declare(strict_types=1);

namespace Vortos\Security\Tests\SupplyChain\Unit\Model\Signature;

use PHPUnit\Framework\TestCase;
use Vortos\Secrets\Value\SecretValue;
use Vortos\Security\SupplyChain\Model\Signature\Signature;
use Vortos\Security\SupplyChain\Model\Signature\SignatureScheme;

final class SignatureTest extends TestCase
{
    public function test_payload_is_redacted_in_to_string(): void
    {
        $sig = new Signature(SignatureScheme::KeylessFulcio, SecretValue::fromString('secret-payload'), 42);
        self::assertStringNotContainsString('secret-payload', (string) $sig);
    }

    public function test_payload_is_redacted_in_to_array(): void
    {
        $sig = new Signature(SignatureScheme::KeyEd25519, SecretValue::fromString('secret-payload'));
        $arr = $sig->toArray();
        self::assertSame('***', $arr['payload']);
    }

    public function test_rekor_log_index(): void
    {
        $sig = new Signature(SignatureScheme::KeylessFulcio, SecretValue::fromString('x'), 12345);
        self::assertSame(12345, $sig->rekorLogIndex);
    }

    public function test_null_rekor_log_index(): void
    {
        $sig = new Signature(SignatureScheme::KeyEd25519, SecretValue::fromString('x'));
        self::assertNull($sig->rekorLogIndex);
    }
}
