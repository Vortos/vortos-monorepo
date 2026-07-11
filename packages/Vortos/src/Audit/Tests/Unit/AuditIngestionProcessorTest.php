<?php

declare(strict_types=1);

namespace Vortos\Audit\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Vortos\Audit\Contract\AuditRecorderInterface;
use Vortos\Audit\Enum\Scope;
use Vortos\Audit\Event\AuditActor;
use Vortos\Audit\Event\AuditEvent;
use Vortos\Audit\Ingestion\AuditIngestionProcessor;
use Vortos\Audit\Ingestion\Idempotency\InMemoryIdempotencyGuard;
use Vortos\Audit\Recorder\BufferingAuditRecorder;

final class AuditIngestionProcessorTest extends TestCase
{
    private function event(string $id = 'a-1'): AuditEvent
    {
        return new AuditEvent(
            id: $id,
            scope: Scope::Tenant,
            tenantId: 'org-1',
            actor: AuditActor::system(),
            action: 'member.invited',
            target: null,
            sensitivity: \Vortos\Audit\Enum\Sensitivity::Normal,
            outcome: \Vortos\Audit\Enum\Outcome::Allowed,
            source: \Vortos\Audit\Event\AuditSource::empty(),
            context: [],
            occurredAt: new \DateTimeImmutable(),
        );
    }

    public function test_processes_event_once(): void
    {
        $store     = new BufferingAuditRecorder();
        $processor = new AuditIngestionProcessor($store, new InMemoryIdempotencyGuard());

        $processor->process($this->event());

        self::assertCount(1, $store->events());
    }

    public function test_skips_duplicate_delivery_via_guard(): void
    {
        $store     = new BufferingAuditRecorder();
        $guard     = new InMemoryIdempotencyGuard();
        $processor = new AuditIngestionProcessor($store, $guard);

        $processor->process($this->event('dup'));
        $processor->process($this->event('dup')); // redelivery

        self::assertCount(1, $store->events());
    }

    public function test_duplicate_key_from_store_is_treated_as_success(): void
    {
        $store = new class implements AuditRecorderInterface {
            public function record(AuditEvent $event): void
            {
                throw new \RuntimeException('SQLSTATE[23505]: duplicate key value violates unique constraint');
            }
        };

        // Should NOT throw — duplicate id means an earlier delivery already persisted it.
        (new AuditIngestionProcessor($store, new InMemoryIdempotencyGuard()))->process($this->event());
        $this->addToAssertionCount(1);
    }

    public function test_genuine_failure_releases_claim_for_retry(): void
    {
        $attempts = 0;
        $store = new class($attempts) implements AuditRecorderInterface {
            public function __construct(public int &$attempts) {}
            public function record(AuditEvent $event): void
            {
                $this->attempts++;
                if ($this->attempts === 1) {
                    throw new \RuntimeException('transient network error');
                }
            }
        };
        $guard     = new InMemoryIdempotencyGuard();
        $processor = new AuditIngestionProcessor($store, $guard);

        try {
            $processor->process($this->event('retry-me'));
            self::fail('expected first attempt to throw');
        } catch (\RuntimeException) {
            // expected
        }

        // Claim was released, so the redelivery is allowed through and succeeds.
        $processor->process($this->event('retry-me'));
        self::assertSame(2, $store->attempts);
    }
}
