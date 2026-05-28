<?php

declare(strict_types=1);

namespace Vortos\Tests\AwsSes\Middleware;

use PHPUnit\Framework\TestCase;
use Vortos\AwsSes\Deduplication\DeduplicationStoreInterface;
use Vortos\AwsSes\Deduplication\InMemoryDeduplicationStore;
use Vortos\AwsSes\Middleware\DeduplicationMiddleware;
use Vortos\AwsSes\ValueObject\Email;
use Vortos\AwsSes\ValueObject\SentEmail;

final class DeduplicationMiddlewareTest extends TestCase
{
    private function makeEmail(?string $idempotencyKey = null, ?string $domainEventId = null): Email
    {
        $email = Email::new()->to('u@example.com')->subject('S')->htmlBody('H');
        if ($idempotencyKey !== null) {
            $email = $email->withMeta('idempotency_key', $idempotencyKey);
        }
        if ($domainEventId !== null) {
            $email = $email->withMeta('domain_event_id', $domainEventId);
        }
        return $email;
    }

    private function makeSentEmail(string $id = 'msg-1'): SentEmail
    {
        return new SentEmail($id, new \DateTimeImmutable(), 1, 'log', null);
    }

    public function test_passes_through_when_no_idempotency_key(): void
    {
        $mw   = new DeduplicationMiddleware(new InMemoryDeduplicationStore());
        $sent = $this->makeSentEmail();

        $result = $mw->process($this->makeEmail(), fn($e) => $sent);

        $this->assertSame($sent, $result);
    }

    public function test_calls_next_on_first_send_with_key(): void
    {
        $mw      = new DeduplicationMiddleware(new InMemoryDeduplicationStore());
        $sent    = $this->makeSentEmail();
        $called  = false;

        $result = $mw->process(
            $this->makeEmail(idempotencyKey: 'key-1'),
            function ($e) use ($sent, &$called): SentEmail {
                $called = true;
                return $sent;
            },
        );

        $this->assertTrue($called);
        $this->assertSame($sent, $result);
    }

    public function test_returns_cached_result_on_duplicate(): void
    {
        $store   = new InMemoryDeduplicationStore();
        $mw      = new DeduplicationMiddleware($store);
        $email   = $this->makeEmail(idempotencyKey: 'key-1');
        $first   = $this->makeSentEmail('first-msg');

        // First send
        $mw->process($email, fn($e) => $first);

        // Second send — should return cached result without calling next
        $nextCalled = false;
        $result     = $mw->process(
            $email,
            function ($e) use (&$nextCalled): SentEmail {
                $nextCalled = true;
                return $this->makeSentEmail('second-msg');
            },
        );

        $this->assertFalse($nextCalled);
        $this->assertSame('first-msg', $result->messageId());
    }

    public function test_falls_back_to_domain_event_id_when_no_idempotency_key(): void
    {
        $mw     = new DeduplicationMiddleware(new InMemoryDeduplicationStore());
        $email  = $this->makeEmail(domainEventId: 'evt-uuid-1');
        $first  = $this->makeSentEmail('first');

        // First send with domain_event_id
        $mw->process($email, fn($e) => $first);

        // Second send with same domain_event_id — should deduplicate
        $nextCalled = false;
        $result     = $mw->process(
            $email,
            function ($e) use (&$nextCalled): SentEmail {
                $nextCalled = true;
                return $this->makeSentEmail('second');
            },
        );

        $this->assertFalse($nextCalled);
        $this->assertSame('first', $result->messageId());
    }

    public function test_idempotency_key_takes_precedence_over_domain_event_id(): void
    {
        $store  = new InMemoryDeduplicationStore();
        $mw     = new DeduplicationMiddleware($store);

        // Pre-seed with idempotency_key
        $cached = $this->makeSentEmail('cached');
        $store->markSent('explicit-key', $cached);

        $email  = $this->makeEmail(idempotencyKey: 'explicit-key', domainEventId: 'evt-uuid');
        $result = $mw->process($email, fn($e) => $this->makeSentEmail('new'));

        $this->assertSame('cached', $result->messageId());
    }

    public function test_different_keys_are_not_deduplicated(): void
    {
        $mw    = new DeduplicationMiddleware(new InMemoryDeduplicationStore());
        $email1 = $this->makeEmail(idempotencyKey: 'key-1');
        $email2 = $this->makeEmail(idempotencyKey: 'key-2');

        $called = 0;
        $mw->process($email1, function ($e) use (&$called): SentEmail { ++$called; return $this->makeSentEmail(); });
        $mw->process($email2, function ($e) use (&$called): SentEmail { ++$called; return $this->makeSentEmail(); });

        $this->assertSame(2, $called);
    }

    public function test_stores_result_after_successful_send(): void
    {
        $store = new InMemoryDeduplicationStore();
        $mw    = new DeduplicationMiddleware($store);
        $sent  = $this->makeSentEmail('stored');

        $mw->process($this->makeEmail(idempotencyKey: 'k'), fn($e) => $sent);

        $this->assertTrue($store->isDuplicate('k'));
        $this->assertSame('stored', $store->getSent('k')?->messageId());
    }

    public function test_exception_not_stored_as_duplicate(): void
    {
        $store = new InMemoryDeduplicationStore();
        $mw    = new DeduplicationMiddleware($store);
        $email = $this->makeEmail(idempotencyKey: 'k');

        try {
            $mw->process($email, fn($e) => throw new \RuntimeException('fail'));
        } catch (\RuntimeException) {}

        $this->assertFalse($store->isDuplicate('k'));
    }

    public function test_emails_without_key_are_never_cached(): void
    {
        $store = new InMemoryDeduplicationStore();
        $mw    = new DeduplicationMiddleware($store);
        $email = $this->makeEmail(); // no key

        $mw->process($email, fn($e) => $this->makeSentEmail());

        $this->assertFalse($store->isDuplicate(''));
    }
}
