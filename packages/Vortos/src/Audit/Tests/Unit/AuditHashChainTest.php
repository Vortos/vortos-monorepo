<?php

declare(strict_types=1);

namespace Vortos\Audit\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Vortos\Audit\Enum\Scope;
use Vortos\Audit\Event\AuditActor;
use Vortos\Audit\Event\AuditEvent;
use Vortos\Audit\Integrity\AuditHashChain;

final class AuditHashChainTest extends TestCase
{
    private function event(string $action = 'member.invited'): AuditEvent
    {
        return AuditEvent::create(Scope::Tenant, 'org-1', AuditActor::system(), $action);
    }

    public function test_content_hash_is_deterministic(): void
    {
        $chain = new AuditHashChain();
        $event = $this->event();

        self::assertSame(
            $chain->contentHash($event, AuditHashChain::GENESIS_HASH),
            $chain->contentHash($event, AuditHashChain::GENESIS_HASH),
        );
    }

    public function test_content_hash_depends_on_prev_hash(): void
    {
        $chain = new AuditHashChain();
        $event = $this->event();

        self::assertNotSame(
            $chain->contentHash($event, AuditHashChain::GENESIS_HASH),
            $chain->contentHash($event, str_repeat('a', 64)),
        );
    }

    public function test_signature_verifies_only_with_correct_key(): void
    {
        $chain = new AuditHashChain();
        $msg   = $chain->signingMessage('id-1', 1, str_repeat('b', 64), AuditHashChain::GENESIS_HASH);
        $sig   = $chain->sign($msg, 'secret-key');

        self::assertTrue($chain->verifySignature($msg, $sig, 'secret-key'));
        self::assertFalse($chain->verifySignature($msg, $sig, 'wrong-key'));
    }

    public function test_signing_with_empty_key_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new AuditHashChain())->sign('msg', '');
    }

    public function test_chain_produces_unsigned_record_when_key_absent(): void
    {
        $stored = (new AuditHashChain())->chain($this->event(), 'tenant:org-1', 1, AuditHashChain::GENESIS_HASH, '');

        self::assertSame('', $stored->signature);
        self::assertSame(1, $stored->sequence);
    }
}
