<?php

declare(strict_types=1);

namespace Vortos\PersistenceOrm\Tests\Tenant;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\FilterCollection;
use PHPUnit\Framework\TestCase;
use Vortos\PersistenceOrm\Tenant\TenantFilter;
use Vortos\PersistenceOrm\Tenant\TenantOrm;
use Vortos\Tenant\TenantContext;

final class TenantOrmTest extends TestCase
{
    public function test_system_scope_disables_filter_runs_in_system_then_restores(): void
    {
        $filters = $this->createMock(FilterCollection::class);
        $filters->method('isEnabled')->with(TenantFilter::NAME)->willReturn(true);
        $filters->expects($this->once())->method('disable')->with(TenantFilter::NAME);
        $filters->expects($this->once())->method('enable')->with(TenantFilter::NAME);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getFilters')->willReturn($filters);

        $context = new TenantContext();
        $context->set('acme');

        $wasSystem = TenantOrm::systemScope($em, $context, function () use ($context) {
            return $context->isSystem();
        });

        $this->assertTrue($wasSystem, 'closure runs in system scope');
        $this->assertFalse($context->isSystem(), 'context restored after');
        $this->assertSame('acme', $context->tenantId());
    }

    public function test_filter_re_enabled_even_on_exception(): void
    {
        $filters = $this->createMock(FilterCollection::class);
        $filters->method('isEnabled')->willReturn(true);
        $filters->expects($this->once())->method('enable')->with(TenantFilter::NAME);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getFilters')->willReturn($filters);

        $this->expectException(\RuntimeException::class);

        try {
            TenantOrm::systemScope($em, new TenantContext(), function (): void {
                throw new \RuntimeException('boom');
            });
        } finally {
            // assertion on enable() is verified by the mock expectation
        }
    }
}
