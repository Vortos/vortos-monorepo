<?php

declare(strict_types=1);

namespace Vortos\Auth\Tests\Tenant;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Vortos\Auth\Middleware\Compiler\TenantMiddlewareCompilerPass;
use Vortos\Auth\Tenant\TenantContextMiddleware;
use Vortos\Tenant\TenantContext;

final class TenantMiddlewareCompilerPassTest extends TestCase
{
    public function test_registers_middleware_when_tenant_context_present(): void
    {
        $container = new ContainerBuilder();
        $container->register(TenantContext::class, TenantContext::class);
        $container->setParameter('vortos.auth.tenant_claim', 'org_id');

        (new TenantMiddlewareCompilerPass())->process($container);

        $this->assertTrue($container->hasDefinition(TenantContextMiddleware::class));
        $definition = $container->getDefinition(TenantContextMiddleware::class);
        $this->assertTrue($definition->hasTag('kernel.event_subscriber'));
        $this->assertSame('org_id', $definition->getArgument(2));
    }

    public function test_no_op_when_tenant_context_absent(): void
    {
        $container = new ContainerBuilder();

        (new TenantMiddlewareCompilerPass())->process($container);

        $this->assertFalse($container->hasDefinition(TenantContextMiddleware::class));
    }

    public function test_does_not_overwrite_existing_middleware_definition(): void
    {
        $container = new ContainerBuilder();
        $container->register(TenantContext::class, TenantContext::class);
        $container->register(TenantContextMiddleware::class, TenantContextMiddleware::class)
            ->addTag('marker');

        (new TenantMiddlewareCompilerPass())->process($container);

        $this->assertTrue($container->getDefinition(TenantContextMiddleware::class)->hasTag('marker'));
    }

    public function test_falls_back_to_org_id_claim_when_parameter_missing(): void
    {
        $container = new ContainerBuilder();
        $container->register(TenantContext::class, TenantContext::class);

        (new TenantMiddlewareCompilerPass())->process($container);

        $this->assertSame('org_id', $container->getDefinition(TenantContextMiddleware::class)->getArgument(2));
    }
}
