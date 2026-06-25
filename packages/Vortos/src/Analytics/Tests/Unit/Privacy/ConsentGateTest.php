<?php

declare(strict_types=1);

namespace Vortos\Analytics\Tests\Unit\Privacy;

use PHPUnit\Framework\TestCase;
use Vortos\Analytics\Event\DistinctId;
use Vortos\Analytics\Privacy\ConsentDecision;
use Vortos\Analytics\Privacy\ConsentGate;
use Vortos\Analytics\Privacy\ConsentResolverInterface;
use Vortos\Analytics\Privacy\DenyAllConsentResolver;

final class ConsentGateTest extends TestCase
{
    public function test_granted_passes(): void
    {
        $gate = new ConsentGate($this->resolverReturning(ConsentDecision::Granted));
        $this->assertTrue($gate->allows(new DistinctId('user-1')));
        $this->assertSame(0, $gate->droppedCount());
    }

    public function test_denied_is_dropped_and_counted(): void
    {
        $gate = new ConsentGate($this->resolverReturning(ConsentDecision::Denied));
        $this->assertFalse($gate->allows(new DistinctId('user-1')));
        $this->assertSame(1, $gate->droppedCount());
    }

    public function test_unknown_is_dropped_and_counted(): void
    {
        $gate = new ConsentGate($this->resolverReturning(ConsentDecision::Unknown));
        $this->assertFalse($gate->allows(new DistinctId('user-1')));
        $this->assertSame(1, $gate->droppedCount());
    }

    public function test_dropped_count_accumulates_across_calls(): void
    {
        $gate = new ConsentGate($this->resolverReturning(ConsentDecision::Denied));
        $gate->allows(new DistinctId('user-1'));
        $gate->allows(new DistinctId('user-2'));
        $gate->allows(new DistinctId('user-3'));

        $this->assertSame(3, $gate->droppedCount());
    }

    public function test_deny_all_resolver_denies_everything(): void
    {
        $gate = new ConsentGate(new DenyAllConsentResolver());
        $this->assertFalse($gate->allows(new DistinctId('anyone')));
        $this->assertFalse($gate->allows(new DistinctId('anyone-else')));
    }

    private function resolverReturning(ConsentDecision $decision): ConsentResolverInterface
    {
        return new class ($decision) implements ConsentResolverInterface {
            public function __construct(private ConsentDecision $decision) {}

            public function resolve(DistinctId $distinctId): ConsentDecision
            {
                return $this->decision;
            }
        };
    }
}
