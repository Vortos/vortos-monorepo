<?php

declare(strict_types=1);

namespace Vortos\Tests\Foundation\Health;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Vortos\Foundation\DependencyInjection\Compiler\HealthCheckPass;
use Vortos\Foundation\Health\Attribute\AsHealthCheck;
use Vortos\Foundation\Health\Contract\HealthCheckInterface;
use Vortos\Foundation\Health\HealthRegistry;
use Vortos\Foundation\Health\HealthResult;

final class HealthCheckPassTest extends TestCase
{
    public function test_compiler_pass_preserves_health_check_metadata(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', sys_get_temp_dir());
        $container->setDefinition(HealthRegistry::class, new Definition(HealthRegistry::class, [[]]));
        $container->setDefinition(AttributedOptionalHealthCheck::class, new Definition(AttributedOptionalHealthCheck::class));

        (new HealthCheckPass())->process($container);

        $checks = $container->findDefinition(HealthRegistry::class)->getArgument('$checks');

        $this->assertCount(1, $checks);
        $this->assertInstanceOf(Reference::class, $checks[0]['check']);
        $this->assertSame(AttributedOptionalHealthCheck::class, (string) $checks[0]['check']);
        $this->assertFalse($checks[0]['critical']);
        $this->assertSame(1234, $checks[0]['timeout_ms']);
    }
}

#[AsHealthCheck(critical: false, timeoutMs: 1234)]
final class AttributedOptionalHealthCheck implements HealthCheckInterface
{
    public function name(): string
    {
        return 'optional';
    }

    public function check(): HealthResult
    {
        return new HealthResult($this->name(), true, 1.0);
    }
}
