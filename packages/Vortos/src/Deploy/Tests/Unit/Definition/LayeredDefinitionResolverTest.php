<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Definition;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Definition\DeploymentDefinition;
use Vortos\Deploy\Definition\LayeredDefinitionResolver;
use Vortos\Deploy\Strategy\DeployStrategy;

final class LayeredDefinitionResolverTest extends TestCase
{
    public function test_resolve_base_when_no_override(): void
    {
        $builder = DeploymentDefinition::create();
        $resolver = new LayeredDefinitionResolver($builder);

        $def = $resolver->resolve('dev');

        self::assertSame('ssh-compose', $def->host);
        self::assertSame(DeployStrategy::BlueGreen, $def->strategy);
    }

    public function test_resolve_applies_env_override(): void
    {
        $builder = DeploymentDefinition::create()
            ->forEnvironment('prod', fn ($b) => $b->strategy('canary'));

        $resolver = new LayeredDefinitionResolver($builder);

        $prod = $resolver->resolve('prod');
        $dev = $resolver->resolve('dev');

        self::assertSame(DeployStrategy::Canary, $prod->strategy);
        self::assertSame(DeployStrategy::BlueGreen, $dev->strategy);
    }

    public function test_drift_report_no_drift(): void
    {
        $builder = DeploymentDefinition::create();
        $resolver = new LayeredDefinitionResolver($builder);

        $report = $resolver->driftReport('dev');

        self::assertFalse($report->hasDrift());
        self::assertSame([], $report->overriddenFields());
    }

    public function test_drift_report_with_override(): void
    {
        $builder = DeploymentDefinition::create()
            ->forEnvironment('prod', fn ($b) => $b->strategy('canary')->autoRollback(false));

        $resolver = new LayeredDefinitionResolver($builder);
        $report = $resolver->driftReport('prod');

        self::assertTrue($report->hasDrift());
        self::assertContains('strategy', $report->overriddenFields());
        self::assertContains('auto_rollback', $report->overriddenFields());

        $arr = $report->toArray();
        self::assertSame('prod', $arr['environment']);
        self::assertTrue($arr['has_drift']);
    }

    public function test_base_definition_not_mutated_by_override(): void
    {
        $builder = DeploymentDefinition::create()
            ->forEnvironment('prod', fn ($b) => $b->host('k8s'));

        $resolver = new LayeredDefinitionResolver($builder);

        $resolver->resolve('prod');
        $base = $resolver->resolve('dev');

        self::assertSame('ssh-compose', $base->host);
    }
}
