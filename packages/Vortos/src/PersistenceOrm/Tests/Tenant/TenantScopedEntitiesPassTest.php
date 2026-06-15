<?php

declare(strict_types=1);

namespace Vortos\PersistenceOrm\Tests\Tenant;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Vortos\PersistenceOrm\DependencyInjection\Compiler\TenantScopedEntitiesPass;

final class TenantScopedEntitiesPassTest extends TestCase
{
    public function test_builds_scoped_entity_map_from_repositories(): void
    {
        $container = new ContainerBuilder();
        $container->register(ScopedRepositoryFixture::class, ScopedRepositoryFixture::class);
        $container->register(GlobalRepositoryFixture::class, GlobalRepositoryFixture::class);

        (new TenantScopedEntitiesPass())->process($container);

        $map = $container->getParameter('vortos.tenant.orm_scoped_entities');

        $this->assertArrayHasKey(ScopedEntityFixture::class, $map);
        $this->assertSame('tenant_id', $map[ScopedEntityFixture::class]);
        $this->assertArrayNotHasKey(GlobalEntityFixture::class, $map);
    }

    public function test_empty_map_when_no_scoped_entities(): void
    {
        $container = new ContainerBuilder();
        $container->register(GlobalRepositoryFixture::class, GlobalRepositoryFixture::class);

        (new TenantScopedEntitiesPass())->process($container);

        $this->assertSame([], $container->getParameter('vortos.tenant.orm_scoped_entities'));
    }
}
